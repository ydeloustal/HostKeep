<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const STATE_DIR = '/var/lib/hostkeep';
const JOBS_DIR = STATE_DIR . '/jobs';
const AUTH_LOG = '/var/log/hostkeep/auth.log';
const HELPER = '/usr/local/sbin/hostkeep-action';

function cfg(): array
{
    static $c = null;
    return $c ??= require APP_ROOT . '/config.php';
}

/** Web connection: OS user "hostkeep" authenticates through the MariaDB unix socket. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=hostkeep;charset=utf8mb4', 'hostkeep', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
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

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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

function security_headers(): void
{
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; connect-src 'self'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Robots-Tag: noindex, nofollow');
}
