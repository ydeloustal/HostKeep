<?php
declare(strict_types=1);

/**
 * Authentication: accounts in the "users" table (bcrypt / argon via password_hash), PHP sessions
 * doubled by a row in "user_sessions" so that sessions can be listed and revoked, CSRF tokens,
 * per-IP and per-account throttling (failures logged for fail2ban).
 */
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/rbac.php';

function idle_timeout(): int
{
    return 60 * max(5, setting_int('security.session_timeout'));
}

function start_session(): void
{
    session_name('hksid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_start();
}

/**
 * The signed-in user (row of "users", without the hash) or null. Checks the server-side session row
 * (revoked = gone), the account state and the idle timeout. $touch=false for background polling,
 * so an open tab does not keep the session alive forever.
 */
function current_user(bool $touch = true): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $cached = null;
    $token = $_SESSION['sid'] ?? '';
    if (!is_string($token) || $token === '') {
        return null;
    }
    $s = db()->prepare('SELECT s.id AS sid, s.last_seen, u.id, u.username, u.email, u.display_name, u.role, u.active, u.must_change_password,
                               u.last_login_at, u.created_at
                        FROM user_sessions s JOIN users u ON u.id = s.user_id WHERE s.id = ?');
    $s->execute([hash('sha256', $token)]);
    $u = $s->fetch();
    if (!$u || !$u['active'] || !isset(ROLES[$u['role']]) || time() - (int) $u['last_seen'] > idle_timeout()) {
        if ($u) {
            db()->prepare('DELETE FROM user_sessions WHERE id = ?')->execute([$u['sid']]);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        return null;
    }
    if ($touch && time() - (int) $u['last_seen'] >= 30) {
        db()->prepare('UPDATE user_sessions SET last_seen = ?, ip = ? WHERE id = ?')->execute([time(), client_ip(), $u['sid']]);
    }
    $u['id'] = (int) $u['id'];
    $u['must_change_password'] = (bool) $u['must_change_password'];
    return $cached = $u;
}

function csrf_token(): string
{
    return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
}

function check_csrf(): bool
{
    $t = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    return is_string($t) && $t !== '' && hash_equals((string) ($_SESSION['csrf'] ?? ''), $t);
}

function max_attempts(): int
{
    return max(3, setting_int('security.max_attempts'));
}

function lockout_seconds(): int
{
    return 60 * max(1, setting_int('security.lockout_minutes'));
}

function too_many_attempts(string $ip): bool
{
    $s = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ok = 0 AND ts > ?');
    $s->execute([$ip, time() - lockout_seconds()]);
    return (int) $s->fetchColumn() >= max_attempts();
}

function record_attempt(string $ip, bool $ok, string $user): void
{
    db()->prepare('INSERT INTO login_attempts (ip, ts, ok) VALUES (?, ?, ?)')->execute([$ip, time(), $ok ? 1 : 0]);
    if (!$ok) {
        // Line format matched by the fail2ban filter "hostkeep".
        $u = preg_replace('/[^A-Za-z0-9_.@-]/', '?', substr($user, 0, 40));
        @file_put_contents(AUTH_LOG, date('c') . " hostkeep auth failure from $ip user=$u\n", FILE_APPEND | LOCK_EX);
    }
}

function find_user_by_login(string $login): ?array
{
    $s = db()->prepare('SELECT * FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?) LIMIT 1');
    $s->execute([$login, $login]);
    return $s->fetch() ?: null;
}

/** @return true|string true on success, otherwise the message to show. */
function attempt_login(string $login, string $pass): bool|string
{
    $ip = client_ip();
    if (too_many_attempts($ip)) {
        return 'Trop de tentatives depuis ton adresse. Réessaie dans ' . intdiv(lockout_seconds(), 60) . ' minutes.';
    }
    $login = trim($login);
    $u = $login !== '' && strlen($login) <= 190 ? find_user_by_login($login) : null;
    // Always verify a hash, so the response time does not reveal whether the account exists.
    $ok = password_verify($pass, $u['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
    $locked = $u && (int) $u['locked_until'] > time();
    if (!$u || !$ok || $locked || !$u['active']) {
        record_attempt($ip, false, $login);
        if ($u && !$ok && !$locked) {
            $fails = (int) $u['failed_logins'] + 1;
            $until = $fails >= max_attempts() ? time() + lockout_seconds() : 0;
            db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')->execute([$until ? 0 : $fails, $until, $u['id']]);
            if ($until) {
                audit('compte verrouillé après ' . $fails . ' échecs', null, 0, $u['username']);
            }
        }
        return $locked ? 'Compte temporairement verrouillé après plusieurs échecs. Réessaie plus tard.'
            : (!$u || !$ok ? 'Identifiants incorrects.' : 'Ce compte est désactivé.');
    }
    record_attempt($ip, true, $login);
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($pass, PASSWORD_DEFAULT), $u['id']]);
    }
    db()->prepare('UPDATE users SET failed_logins = 0, locked_until = 0, last_login_at = ?, last_login_ip = ? WHERE id = ?')->execute([time(), $ip, $u['id']]);
    open_user_session((int) $u['id']);
    audit('connexion', null, 0, $u['username']);
    return true;
}

/** New PHP session id + a fresh row in user_sessions. */
function open_user_session(int $userId): void
{
    session_regenerate_id(true);
    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO user_sessions (id, user_id, created_at, last_seen, ip, user_agent) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $userId, time(), time(), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255)]);
    $_SESSION = ['sid' => $token, 'csrf' => bin2hex(random_bytes(32))];
}

function logout(): void
{
    $token = $_SESSION['sid'] ?? '';
    if (is_string($token) && $token !== '') {
        db()->prepare('DELETE FROM user_sessions WHERE id = ?')->execute([hash('sha256', $token)]);
    }
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function current_session_id(): string
{
    $t = $_SESSION['sid'] ?? '';
    return is_string($t) && $t !== '' ? hash('sha256', $t) : '';
}

/* ---------- passwords ---------- */

function password_min_length(): int
{
    return max(8, setting_int('security.password_min_length'));
}

/** @return string|null error message, null when the password is acceptable */
function password_problem(string $pass, ?array $user = null): ?string
{
    $min = password_min_length();
    if (mb_strlen($pass) < $min) {
        return "Le mot de passe doit faire au moins $min caractères.";
    }
    if (strlen($pass) > 1000) {
        return 'Mot de passe trop long.';
    }
    if ($user && (strcasecmp($pass, (string) $user['username']) === 0 || ($user['email'] ?? '') !== '' && strcasecmp($pass, (string) $user['email']) === 0)) {
        return 'Le mot de passe ne doit pas être ton identifiant.';
    }
    if (count(array_unique(mb_str_split($pass))) < 5) {
        return 'Mot de passe trop simple (trop peu de caractères différents).';
    }
    return null;
}

/** Sets a new password, ends the other sessions of the account (all of them when $keepSession is empty). */
function set_password(int $userId, string $pass, bool $mustChange = false, string $keepSession = ''): void
{
    db()->prepare('UPDATE users SET password_hash = ?, must_change_password = ?, password_changed_at = ?, updated_at = ?, failed_logins = 0, locked_until = 0 WHERE id = ?')
        ->execute([password_hash($pass, PASSWORD_DEFAULT), $mustChange ? 1 : 0, time(), time(), $userId]);
    db()->prepare('DELETE FROM user_sessions WHERE user_id = ? AND id <> ?')->execute([$userId, $keepSession]);
    db()->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
}

/** Random password, easy to type (no ambiguous characters). */
function generate_password(int $len = 16): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
        if ($i % 4 === 3 && $i < $len - 1) {
            $out .= '-';
        }
    }
    return $out;
}

/* ---------- one-time links (reset / invitation) ---------- */

/** @return string the token to put in the link (only its hash is stored) */
function create_reset_token(int $userId, int $ttl): string
{
    $token = bin2hex(random_bytes(32));
    db()->prepare('DELETE FROM password_resets WHERE user_id = ? OR expires_at < ?')->execute([$userId, time()]);
    db()->prepare('INSERT INTO password_resets (token_hash, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
        ->execute([hash('sha256', $token), $userId, time(), time() + $ttl]);
    return $token;
}

function user_from_reset_token(string $token): ?array
{
    if (!preg_match('/^[0-9a-f]{64}$/D', $token)) {
        return null;
    }
    $s = db()->prepare('SELECT u.* FROM password_resets r JOIN users u ON u.id = r.user_id WHERE r.token_hash = ? AND r.expires_at > ? AND u.active = 1');
    $s->execute([hash('sha256', $token), time()]);
    return $s->fetch() ?: null;
}

/** Sends the "forgotten password" e-mail when the address matches an active account. Always silent. */
function request_password_reset(string $login): void
{
    $u = find_user_by_login(trim($login));
    if (!$u || !$u['active'] || !$u['email'] || !mail_configured() || !setting_bool('security.password_reset')) {
        return;
    }
    $recent = db()->prepare('SELECT COUNT(*) FROM password_resets WHERE user_id = ? AND created_at > ?');
    $recent->execute([$u['id'], time() - 300]);
    if ((int) $recent->fetchColumn() > 0) {
        return;   // one e-mail per 5 minutes and per account
    }
    $link = base_url() . '/password.php?token=' . create_reset_token((int) $u['id'], 3600);
    try {
        send_mail($u['email'], 'Réinitialisation de ton mot de passe',
            "Bonjour {$u['display_name']},\n\nUne réinitialisation du mot de passe de ton compte « {$u['username']} » sur " . panel_name() . " a été demandée depuis l'adresse " . client_ip() . ".\n\n"
            . "Pour choisir un nouveau mot de passe, ouvre ce lien (valable 1 heure) :\n$link\n\nSi tu n'es pas à l'origine de cette demande, ignore ce message : ton mot de passe actuel reste valable.\n");
        audit('demande de réinitialisation du mot de passe', null, 0, $u['username']);
    } catch (Throwable $e) {
        error_log('hostkeep: reset mail: ' . $e->getMessage());
    }
}
