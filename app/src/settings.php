<?php
declare(strict_types=1);

/**
 * Panel settings, stored in the "settings" table and edited from Administration › Paramètres.
 * Each group is guarded by one permission (app/src/rbac.php). Values are validated here, never trusted
 * from the browser. Secrets (SMTP password) are encrypted with HOSTKEEP_APP_KEY and never sent back.
 *
 * Field: key => [type, default, label, help, extra]
 *   types: text, email, emails, int, bool, select, time, password, cidrs
 */
function settings_schema(): array
{
    return [
        'general' => ['label' => 'Général', 'perm' => 'settings.general', 'fields' => [
            'general.panel_name' => ['text', 'Hostkeep', 'Nom du panel', 'Affiché dans le titre des pages, sur la page de connexion et dans les e-mails.', ['max' => 40]],
            'general.base_url' => ['text', '', 'Adresse publique du panel', 'Utilisée dans les liens des e-mails (réinitialisation, invitation). Vide = https://HOSTKEEP_DOMAIN.', ['max' => 200, 'placeholder' => 'https://admin.example.com']],
        ]],
        'smtp' => ['label' => 'E-mails (SMTP)', 'perm' => 'settings.smtp', 'fields' => [
            'smtp.host' => ['text', '', 'Serveur SMTP', 'Vide = envoi d\'e-mails désactivé (mot de passe oublié, invitations, alertes).', ['max' => 190, 'placeholder' => 'smtp.example.com']],
            'smtp.port' => ['int', '587', 'Port', '587 (STARTTLS), 465 (TLS) ou 25.', ['min' => 1, 'max' => 65535]],
            'smtp.encryption' => ['select', 'starttls', 'Chiffrement', '', ['options' => ['starttls' => 'STARTTLS', 'tls' => 'TLS implicite (465)', 'none' => 'Aucun (déconseillé)']]],
            'smtp.username' => ['text', '', 'Identifiant', 'Vide = pas d\'authentification.', ['max' => 190]],
            'smtp.password' => ['password', '', 'Mot de passe', 'Stocké chiffré. Laisse vide pour garder l\'actuel.', []],
            'smtp.from_email' => ['email', '', 'Adresse d\'expédition', '', ['placeholder' => 'hostkeep@example.com']],
            'smtp.from_name' => ['text', 'Hostkeep', 'Nom d\'expéditeur', '', ['max' => 80]],
        ]],
        'notifications' => ['label' => 'Alertes par e-mail', 'perm' => 'settings.notifications', 'fields' => [
            'notify.enabled' => ['bool', '0', 'Envoyer les nouvelles alertes par e-mail', 'Vérifié toutes les 5 minutes, un seul e-mail par nouvelle alerte.', []],
            'notify.level' => ['select', 'crit', 'Niveau minimum', '', ['options' => ['crit' => 'Critiques seulement', 'warn' => 'Critiques et avertissements']]],
            'notify.recipients' => ['emails', '', 'Destinataires', 'Adresses séparées par des virgules. Vide = tous les Super Admins et Administrateurs actifs ayant un e-mail.', []],
        ]],
        'backups' => ['label' => 'Sauvegardes', 'perm' => 'settings.backups', 'fields' => [
            'backup.enabled' => ['bool', '1', 'Sauvegarde automatique des bases', 'Un dump compressé de chaque base MariaDB, chaque nuit.', []],
            'backup.time' => ['time', '03:10', 'Heure de la sauvegarde', 'Heure du serveur. Appliquée dans la minute.', []],
            'backup.keep_days' => ['int', '7', 'Durée de conservation (jours)', 'Les sauvegardes plus anciennes sont supprimées.', ['min' => 1, 'max' => 365]],
        ]],
        'security' => ['label' => 'Sécurité', 'perm' => 'settings.security', 'fields' => [
            'security.session_timeout' => ['int', (string) max(5, intdiv((int) env('HOSTKEEP_SESSION_TIMEOUT', '1800'), 60)), 'Déconnexion après inactivité (minutes)', '', ['min' => 5, 'max' => 1440]],
            'security.password_min_length' => ['int', '12', 'Longueur minimale des mots de passe', 'S\'applique aux prochains changements de mot de passe.', ['min' => 8, 'max' => 128]],
            'security.max_attempts' => ['int', '5', 'Échecs de connexion avant blocage', 'Par adresse IP et par compte.', ['min' => 3, 'max' => 50]],
            'security.lockout_minutes' => ['int', '15', 'Durée du blocage (minutes)', '', ['min' => 1, 'max' => 1440]],
            'security.password_reset' => ['bool', '1', 'Autoriser « Mot de passe oublié »', 'Nécessite un serveur SMTP configuré.', []],
            'security.ip_allowlist' => ['cidrs', '', 'Adresses IP autorisées', 'Une IP ou un réseau (CIDR) par ligne, ex. 203.0.113.10 ou 10.8.0.0/24. Vide = accès depuis partout. Ton IP actuelle doit en faire partie.', []],
        ]],
    ];
}

function settings_fields(): array
{
    $all = [];
    foreach (settings_schema() as $g) {
        $all += $g['fields'];
    }
    return $all;
}

/** Raw stored values, cached for the request. */
function settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        try {
            $cache = db()->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $cache = [];   // table missing (old schema): defaults
        }
    }
    return $cache;
}

function setting(string $key): string
{
    $f = settings_fields()[$key] ?? null;
    $v = settings_all()[$key] ?? null;
    if ($v === null) {
        return $f ? $f[1] : '';
    }
    return ($f && $f[0] === 'password') ? secret_decrypt($v) : $v;
}

function setting_int(string $key): int
{
    return (int) setting($key);
}

function setting_bool(string $key): bool
{
    return setting($key) === '1';
}

function panel_name(): string
{
    return setting('general.panel_name') ?: 'Hostkeep';
}

/** Public address used in e-mails. */
function base_url(): string
{
    $u = rtrim(setting('general.base_url'), '/');
    if ($u !== '') {
        return $u;
    }
    $d = env('HOSTKEEP_DOMAIN');
    $scheme = env('HOSTKEEP_HTTPS', 'yes') === 'no' ? 'http' : 'https';
    return $d !== '' ? "$scheme://$d" : (PHP_SAPI !== 'cli' ? (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') : 'http://localhost');
}

/** Settings of the groups the user may see, ready for the form (secrets are never returned). */
function settings_for(array $user): array
{
    $out = [];
    foreach (settings_schema() as $gid => $g) {
        if (!can($user, 'settings.view')) {
            continue;
        }
        $fields = [];
        foreach ($g['fields'] as $key => [$type, $default, $label, $help, $extra]) {
            $raw = settings_all()[$key] ?? null;
            $fields[] = ['key' => $key, 'type' => $type, 'label' => $label, 'help' => $help, 'default' => $type === 'password' ? '' : $default,
                'value' => $type === 'password' ? '' : ($raw ?? $default), 'set' => $type === 'password' ? ($raw ?? '') !== '' : null] + $extra;
        }
        $out[] = ['id' => $gid, 'label' => $g['label'], 'editable' => can($user, $g['perm']), 'fields' => $fields];
    }
    return $out;
}

/**
 * Validates and stores the values of one group. @return array list of errors (field => message), empty on success.
 */
function settings_save(string $group, array $in, array $user): array
{
    $g = settings_schema()[$group] ?? null;
    if (!$g) {
        return ['_' => 'Groupe de paramètres inconnu.'];
    }
    $errors = [];
    $clean = [];
    foreach ($g['fields'] as $key => [$type, $default, $label, $help, $extra]) {
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $v = is_scalar($in[$key]) ? trim((string) $in[$key]) : '';
        $err = null;
        switch ($type) {
            case 'text':
                if (mb_strlen($v) > ($extra['max'] ?? 255) || preg_match('/[\x00-\x1F\x7F]/u', $v)) {
                    $err = 'Valeur invalide ou trop longue.';
                } elseif ($key === 'general.base_url' && $v !== '' && !preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#D', $v)) {
                    $err = 'Adresse invalide (ex. https://admin.example.com).';
                } elseif ($key === 'smtp.host' && $v !== '' && !preg_match('/^[A-Za-z0-9.-]{1,190}$/D', $v)) {
                    $err = 'Nom d\'hôte invalide.';
                } elseif ($key === 'general.panel_name' && $v === '') {
                    $err = 'Obligatoire.';
                }
                break;
            case 'email':
                if ($v !== '' && !valid_email($v)) {
                    $err = 'Adresse e-mail invalide.';
                }
                break;
            case 'emails':
                $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $v)), 'strlen'));
                foreach ($list as $e) {
                    if (!valid_email($e)) {
                        $err = "Adresse invalide : $e";
                    }
                }
                $v = implode(', ', $list);
                break;
            case 'int':
                if (!preg_match('/^\d{1,6}$/D', $v) || (int) $v < ($extra['min'] ?? 0) || (int) $v > ($extra['max'] ?? PHP_INT_MAX)) {
                    $err = sprintf('Nombre entre %d et %d.', $extra['min'] ?? 0, $extra['max'] ?? 999999);
                }
                $v = (string) (int) $v;
                break;
            case 'bool':
                $v = in_array($v, ['1', 'true', 'on'], true) ? '1' : '0';
                break;
            case 'select':
                if (!isset($extra['options'][$v])) {
                    $err = 'Choix invalide.';
                }
                break;
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $v)) {
                    $err = 'Heure au format HH:MM.';
                }
                break;
            case 'password':
                if ($v === '') {
                    continue 2;   // keep the stored one
                }
                if (strlen($v) > 500) {
                    $err = 'Trop long.';
                }
                break;
            case 'cidrs':
                $list = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $v)), 'strlen'));
                foreach ($list as $c) {
                    if (!valid_cidr($c)) {
                        $err = "Adresse ou réseau invalide : $c";
                    }
                }
                if (!$err && $list && PHP_SAPI !== 'cli' && !ip_in_list(client_ip(), $list)) {
                    $err = 'Ton adresse IP actuelle (' . client_ip() . ') n\'est pas dans la liste : tu serais bloqué.';
                }
                $v = implode("\n", $list);
                break;
        }
        if ($err) {
            $errors[$key] = $err;
        } else {
            $clean[$key] = $v;
        }
    }
    if ($errors) {
        return $errors;
    }
    $old = settings_all();
    $st = db()->prepare('REPLACE INTO settings (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)');
    $changed = [];
    foreach ($clean as $key => $v) {
        $isSecret = settings_fields()[$key][0] === 'password';
        if (!$isSecret && ($old[$key] ?? settings_fields()[$key][1]) === $v) {
            continue;
        }
        $st->execute([$key, $isSecret ? secret_encrypt($v) : $v, time(), $user['username']]);
        $changed[] = $key;
    }
    settings_all(true);
    if ($changed) {
        audit('settings ' . $group . ' : ' . implode(', ', $changed));
    }
    return [];
}

function valid_email(string $e): bool
{
    return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $e);
}

function valid_cidr(string $c): bool
{
    [$ip, $bits] = array_pad(explode('/', $c, 2), 2, null);
    $bin = @inet_pton((string) $ip);
    if ($bin === false) {
        return false;
    }
    return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($bin) * 8);
}

function ip_in_list(string $ip, array $list): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($list as $c) {
        [$net, $bits] = array_pad(explode('/', $c, 2), 2, null);
        $nb = @inet_pton((string) $net);
        if ($nb === false || strlen($nb) !== strlen($bin)) {
            continue;
        }
        $bits = $bits === null ? strlen($nb) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
            continue;
        }
        if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($nb[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

/** Stops the request when an IP allow-list is set and the client is not in it. */
function enforce_ip_allowlist(bool $json = false): void
{
    $list = array_filter(explode("\n", setting('security.ip_allowlist')), 'strlen');
    if (!$list || ip_in_list(client_ip(), $list)) {
        return;
    }
    if ($json) {
        json_out(['error' => 'Accès refusé depuis cette adresse IP.'], 403);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Accès refusé depuis cette adresse IP (' . client_ip() . ").\n";
    exit;
}

/* ---------- secrets (AES-256-GCM, key = HOSTKEEP_APP_KEY from .env) ---------- */

function app_key(): ?string
{
    $k = base64_decode(env('HOSTKEEP_APP_KEY'), true);
    return $k !== false && strlen($k) === 32 ? $k : null;
}

function secret_encrypt(string $plain): string
{
    $key = app_key();
    if ($plain === '' || $key === null) {
        return $plain === '' ? '' : 'plain:' . base64_encode($plain);
    }
    $iv = random_bytes(12);
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}

function secret_decrypt(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    if (str_starts_with($stored, 'plain:')) {
        return (string) base64_decode(substr($stored, 6));
    }
    $key = app_key();
    $raw = base64_decode(substr($stored, 3), true);
    if ($key === null || !str_starts_with($stored, 'v1:') || $raw === false || strlen($raw) < 29) {
        return '';
    }
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}
