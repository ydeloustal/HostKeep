<?php
declare(strict_types=1);

/**
 * Reads the install's .env file (one KEY=value per line, # starts a comment line).
 * Values may be wrapped in single or double quotes. Nothing is evaluated or expanded.
 */
function env(string $key, string $default = ''): string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = __DIR__ . '/../.env';
        foreach (is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (!preg_match('/^\s*([A-Z][A-Z0-9_]*)\s*=\s*(.*?)\s*$/', $line, $m)) {
                continue;
            }
            $v = $m[2];
            if (preg_match('/^([\'"])(.*)\1$/', $v, $q)) {
                $v = $q[2];
            }
            $vars[$m[1]] = $v;
        }
    }
    $v = $vars[$key] ?? '';
    return $v === '' ? $default : $v;
}

/** PHP version used for the PHP-FPM paths, e.g. "8.4". */
function php_version(): string
{
    return env('HOSTKEEP_PHP_VERSION', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
}

/**
 * Services the panel shows and may restart. HOSTKEEP_SERVICES if set, otherwise the usual
 * ones that exist on this server (needs systemctl: collector only, not the web pool).
 */
function managed_services(): array
{
    $list = preg_split('/\s+/', env('HOSTKEEP_SERVICES'), -1, PREG_SPLIT_NO_EMPTY);
    if ($list) {
        return $list;
    }
    $found = [];
    foreach (['apache2', 'nginx', 'php' . php_version() . '-fpm', 'mariadb', 'mysql', 'fail2ban', 'cron', 'postfix'] as $s) {
        if ($s === 'mysql' && in_array('mariadb', $found, true)) {
            continue;   // on Debian, mysql.service is an alias of mariadb.service
        }
        exec('systemctl cat ' . escapeshellarg("$s.service") . ' >/dev/null 2>&1', $_, $rc);
        if ($rc === 0) {
            $found[] = $s;
        }
    }
    return $found;
}
