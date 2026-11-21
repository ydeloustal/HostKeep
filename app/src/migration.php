<?php
declare(strict_types=1);

/**
 * Migration archive (Administration › Version du panel › Archive de migration) and its import by restore.sh.
 * What leaves the server: the panel settings WITHOUT any secret (SMTP password, rclone keys, webhooks, ping URL),
 * and, only if asked, the accounts (username, e-mail, role, password hash) encrypted with a passphrase.
 * Never exported: 2FA secrets and recovery codes, sessions, HOSTKEEP_APP_KEY.
 */

const MIGRATION_KDF_ITER = 200000;

function migration_export(bool $withAccounts, string $passphrase): array
{
    $settings = [];
    foreach (settings_fields() as $key => [$type]) {
        $raw = settings_all()[$key] ?? null;
        if ($raw !== null && !is_secret_type($type)) {
            $settings[$key] = $raw;
        }
    }
    $secretsLeft = array_values(array_filter(array_keys(settings_fields()), fn($k) => is_secret_type(settings_fields()[$k][0]) && (settings_all()[$k] ?? '') !== ''));
    $out = [
        'manifest' => ['format' => 1, 'created' => time(), 'host' => gethostname(), 'version' => app_version(), 'panel_name' => panel_name(),
            'domain' => env('HOSTKEEP_DOMAIN'), 'webserver' => env('HOSTKEEP_WEBSERVER'), 'https' => env('HOSTKEEP_HTTPS', 'yes'),
            'letsencrypt_email' => env('HOSTKEEP_LETSENCRYPT_EMAIL'), 'php' => php_version(),
            'accounts' => $withAccounts, 'secrets_to_reenter' => $secretsLeft],
        'settings' => $settings,
        'accounts' => null,
    ];
    if ($withAccounts) {
        $users = db()->query('SELECT username, email, display_name, password_hash, role, active, must_change_password, created_at FROM users ORDER BY id')->fetchAll();
        $out['accounts'] = migration_encrypt(json_encode($users, JSON_UNESCAPED_UNICODE), $passphrase);
    }
    return $out;
}

/** AES-256-GCM, key from the passphrase with PBKDF2-SHA256. @return array JSON-ready */
function migration_encrypt(string $plain, string $passphrase): array
{
    $salt = random_bytes(16);
    $iv = random_bytes(12);
    $key = hash_pbkdf2('sha256', $passphrase, $salt, MIGRATION_KDF_ITER, 32, true);
    $data = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return ['format' => 1, 'cipher' => 'aes-256-gcm', 'kdf' => 'pbkdf2-sha256', 'iter' => MIGRATION_KDF_ITER,
        'salt' => base64_encode($salt), 'iv' => base64_encode($iv), 'tag' => base64_encode($tag), 'data' => base64_encode($data)];
}

/** @return string|null null when the passphrase is wrong or the file damaged */
function migration_decrypt(array $box, string $passphrase): ?string
{
    if (($box['cipher'] ?? '') !== 'aes-256-gcm' || ($box['kdf'] ?? '') !== 'pbkdf2-sha256') {
        return null;
    }
    $key = hash_pbkdf2('sha256', $passphrase, (string) base64_decode($box['salt']), (int) $box['iter'], 32, true);
    $p = openssl_decrypt((string) base64_decode($box['data']), 'aes-256-gcm', $key, OPENSSL_RAW_DATA,
        (string) base64_decode($box['iv']), (string) base64_decode($box['tag']));
    return $p === false ? null : $p;
}

/** Settings of the archive (only known, non-secret keys). @return int how many were applied */
function migration_import_settings(array $settings): int
{
    $fields = settings_fields();
    $st = db()->prepare('REPLACE INTO settings (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)');
    $n = 0;
    foreach ($settings as $key => $value) {
        if (isset($fields[$key]) && !is_secret_type($fields[$key][0]) && is_string($value) && strlen($value) <= 5000) {
            $st->execute([$key, $value, time(), 'restauration']);
            $n++;
        }
    }
    return $n;
}

/** Accounts of the archive; existing usernames are left alone. @return array{added:string[],skipped:string[]} */
function migration_import_accounts(array $users): array
{
    $res = ['added' => [], 'skipped' => []];
    $exists = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)');
    $ins = db()->prepare('INSERT INTO users (username, email, display_name, password_hash, role, active, must_change_password, created_at, updated_at, password_changed_at)
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($users as $u) {
        if (!is_array($u) || !preg_match('/^[A-Za-z0-9_.-]{3,40}$/D', (string) ($u['username'] ?? '')) || !isset(ROLES[$u['role'] ?? ''])
            || !is_string($u['password_hash'] ?? null) || !str_starts_with($u['password_hash'], '$')) {
            continue;
        }
        $email = isset($u['email']) && is_string($u['email']) && $u['email'] !== '' ? $u['email'] : null;
        $exists->execute([$u['username'], $email]);
        if ((int) $exists->fetchColumn() > 0) {
            $res['skipped'][] = $u['username'];
            continue;
        }
        $now = time();
        $ins->execute([$u['username'], $email, mb_substr((string) ($u['display_name'] ?? ''), 0, 80), $u['password_hash'], $u['role'],
            !empty($u['active']) ? 1 : 0, !empty($u['must_change_password']) ? 1 : 0, (int) ($u['created_at'] ?? $now), $now, $now]);
        $res['added'][] = $u['username'];
    }
    return $res;
}
