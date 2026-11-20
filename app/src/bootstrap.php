<?php
declare(strict_types=1);

require_once __DIR__ . '/env.php';

const APP_ROOT = __DIR__ . '/..';
const STATE_DIR = '/var/lib/hostkeep';
const JOBS_DIR = STATE_DIR . '/jobs';
const AUTH_LOG = '/var/log/hostkeep/auth.log';
const HELPER = '/usr/local/sbin/hostkeep-action';
const APP_VERSION_FILE = APP_ROOT . '/VERSION';

/**
 * MariaDB through the unix socket, no password anywhere: the web pool runs as OS user "hostkeep"
 * (MariaDB account of the same name), the collector and the CLI tools as root.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $user = function_exists('posix_geteuid') && posix_geteuid() === 0 ? 'root' : 'hostkeep';
        $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=hostkeep;charset=utf8mb4', $user, '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function json_out(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Client address as seen by PHP. Behind a reverse proxy, the web server rewrites it (real_ip / mod_remoteip). */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** True when the browser reached the panel over HTTPS (directly, or through a TLS-terminating proxy that the web server trusts). */
function is_https(): bool
{
    $h = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return ($h !== '' && $h !== 'off') || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;
}

function snapshot(string $name): ?array
{
    $s = db()->prepare('SELECT data, updated FROM snapshots WHERE name = ?');
    $s->execute([$name]);
    $r = $s->fetch();
    return $r ? ['updated' => (int) $r['updated'], 'data' => json_decode($r['data'], true)] : null;
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_version(): string
{
    return trim((string) @file_get_contents(APP_VERSION_FILE)) ?: 'dev';
}

function security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

/** Writes one line to the actions journal (audit_log). $job is set for root actions only. */
function audit(string $action, ?string $job = null, ?int $rc = 0, ?string $user = null): void
{
    db()->prepare('INSERT INTO audit_log (ts, ip, user, action, job, rc) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([time(), PHP_SAPI === 'cli' ? 'cli' : client_ip(), $user ?? (current_user()['username'] ?? '-'), mb_substr($action, 0, 255), $job, $job ? null : $rc]);
}
