<?php
declare(strict_types=1);

const IDLE_TIMEOUT = 1800;   // 30 min without user activity
const MAX_FAILS = 5;         // per IP ...
const FAIL_WINDOW = 900;     // ... within 15 min

function start_session(): void
{
    session_name('dashsid');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    ini_set('session.use_strict_mode', '1');
    session_start();
}

/** $touch=false for background polling, so an open tab does not keep the session alive forever. */
function is_logged(bool $touch = true): bool
{
    if (empty($_SESSION['user'])) {
        return false;
    }
    if (time() - (int) ($_SESSION['seen'] ?? 0) > IDLE_TIMEOUT) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    if ($touch) {
        $_SESSION['seen'] = time();
    }
    return true;
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

function too_many_attempts(string $ip): bool
{
    $s = db()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND ok = 0 AND ts > ?');
    $s->execute([$ip, time() - FAIL_WINDOW]);
    return (int) $s->fetchColumn() >= MAX_FAILS;
}

function record_attempt(string $ip, bool $ok, string $user): void
{
    db()->prepare('INSERT INTO login_attempts (ip, ts, ok) VALUES (?, ?, ?)')->execute([$ip, time(), $ok ? 1 : 0]);
    if (!$ok) {
        // Line format matched by the fail2ban filter "dashboard".
        $u = preg_replace('/[^A-Za-z0-9_.@-]/', '?', substr($user, 0, 40));
        @file_put_contents(AUTH_LOG, date('c') . " dashboard auth failure from $ip user=$u\n", FILE_APPEND | LOCK_EX);
    }
}

/** @return true|string true on success, otherwise an error message. */
function attempt_login(string $user, string $pass): bool|string
{
    $ip = client_ip();
    $hash = (string) (cfg()['password_hash'] ?? '');
    if ($hash === '') {
        return 'Aucun mot de passe défini. Sur le serveur : sudo php /var/www/admin-dashboard/bin/set-password.php';
    }
    if (too_many_attempts($ip)) {
        return 'Trop de tentatives. Réessaie dans 15 minutes.';
    }
    $userOk = hash_equals((string) cfg()['admin_user'], $user);
    $passOk = password_verify($pass, $hash);   // always run, so timing does not reveal the user name
    if (!($userOk && $passOk)) {
        record_attempt($ip, false, $user);
        return 'Identifiants incorrects.';
    }
    record_attempt($ip, true, $user);
    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    $_SESSION['seen'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return true;
}
