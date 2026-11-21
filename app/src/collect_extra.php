<?php
declare(strict_types=1);

/** Collector tasks for backups, traffic, performance and the security scan (run as root by bin/collect.php). */

const BACKUP_DIR = '/var/backups/hostkeep';
const TRAFFIC_STATE = '/var/lib/hostkeep/traffic.json';
const BOT_RE = '/bot|crawl|spider|slurp|curl|wget|python|go-http|httpclient|scan|nmap|zgrab|masscan|libwww|okhttp|java\/|headless|monitor|uptime|axios|node-fetch|preview|facebookexternalhit|hostkeep-/i';
const STATIC_RE = '/\.(css|js|mjs|map|png|jpe?g|gif|svg|ico|webp|avif|woff2?|ttf|eot|otf|mp4|webm|mp3|webmanifest|json|txt|xml)$/i';

/* ---------- backups: databases and files ---------- */

function backup_keep_days(): int
{
    return max(1, setting_int('backup.keep_days'));
}

/** Folders saved as files/<name>.tar.gz (paths relative to /), when they exist. */
const BACKUP_SOURCES = ['www' => 'var/www', 'apache2' => 'etc/apache2', 'nginx' => 'etc/nginx', 'letsencrypt' => 'etc/letsencrypt', 'hostkeep' => 'etc/hostkeep'];

function backup_files_keep_days(): int
{
    return min(backup_keep_days(), max(1, setting_int('backup.files_keep_days')));
}

function human_bytes(float $b): string
{
    foreach (['o', 'Ko', 'Mo', 'Go', 'To'] as $u) {
        if ($b < 1024 || $u === 'To') {
            return round($b, $u === 'o' ? 0 : 1) . " $u";
        }
        $b /= 1024;
    }
    return '';
}

/**
 * Nightly backup (and "Sauvegarder maintenant"): one dump per database, then the files when enabled,
 * then manifest.json (what was saved, sizes, sha256, errors). Old days are pruned afterwards.
 */
function c_backup(): void
{
    global $pdo, $argv;
    if (!setting_bool('backup.enabled') && !in_array('--force', $argv, true)) {
        echo "Sauvegarde automatique désactivée (Administration › Paramètres › Sauvegardes).\n";
        return;
    }
    $started = time();
    $dir = BACKUP_DIR . '/' . date('Y-m-d');
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    chgrp($dir, 'hostkeep');
    $dbs = $pdo->query("SELECT schema_name FROM information_schema.schemata
                        WHERE schema_name NOT IN ('information_schema', 'performance_schema', 'sys')")->fetchAll(PDO::FETCH_COLUMN);
    $errors = [];
    $items = [];
    foreach ($dbs as $db) {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $db)) {
            continue;
        }
        $f = "$dir/$db.sql.gz";
        $cmd = 'set -o pipefail; mysqldump --single-transaction --quick --routines --triggers --events --skip-dump-date '
            . escapeshellarg($db) . ' | gzip -6 > ' . escapeshellarg("$f.tmp");
        $out = [];
        exec('bash -c ' . escapeshellarg($cmd) . ' 2>&1', $out, $rc);
        if ($rc === 0) {
            rename("$f.tmp", $f);
            chgrp($f, 'hostkeep');
            chmod($f, 0640);
            $items[] = ['kind' => 'db', 'name' => $db, 'file' => "$db.sql.gz", 'size' => filesize($f), 'sha256' => hash_file('sha256', $f)];
            echo "base $db : " . human_bytes(filesize($f)) . "\n";
        } else {
            @unlink("$f.tmp");
            $errors[] = "base $db : " . implode(' ', $out);
        }
    }
    if (setting_bool('backup.files_enabled')) {
        backup_files($dir, $items, $errors);
    }
    $manifest = ['host' => gethostname(), 'version' => app_version(),
        'started' => $started, 'finished' => time(), 'items' => $items, 'errors' => $errors];
    file_put_contents("$dir/manifest.json.tmp", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    chgrp("$dir/manifest.json.tmp", 'hostkeep');
    chmod("$dir/manifest.json.tmp", 0640);
    rename("$dir/manifest.json.tmp", "$dir/manifest.json");
    backup_prune();
    if ($errors) {
        throw new RuntimeException(implode(' | ', $errors));
    }
}

/** files/<name>.tar.gz for each folder of BACKUP_SOURCES (root only: they hold private keys and site secrets). */
function backup_files(string $dir, array &$items, array &$errors): void
{
    $sources = array_filter(BACKUP_SOURCES, fn($p) => is_dir("/$p"));
    if (!$sources) {
        return;
    }
    $excludes = array_values(array_filter(explode("\n", setting('backup.exclude')), 'strlen'));
    // Space check: the previous archives (or the raw size of the folders) + 10 %, and keep 1 Gio free.
    $need = (int) (backup_files_estimate($sources, $excludes, basename($dir)) * 1.1) + 1024 ** 3;
    $free = (int) @disk_free_space(BACKUP_DIR);
    if ($free < $need) {
        $errors[] = 'fichiers non sauvegardés : ' . human_bytes($free) . ' libres sur le disque, il en faut environ ' . human_bytes($need)
            . ' (réduis la conservation des fichiers ou ajoute des exclusions)';
        return;
    }
    $fdir = "$dir/files";
    if (!is_dir($fdir)) {
        mkdir($fdir, 0700);
    }
    chmod($fdir, 0700);
    foreach ($sources as $name => $path) {
        $f = "$fdir/$name.tar.gz";
        $cmd = ['nice', '-n', '10', 'tar', '--create', '--gzip', '--file', "$f.tmp", '--exclude-caches', '--ignore-failed-read',
            '--warning=no-file-changed', '--warning=no-file-removed', '--warning=no-file-shrank', '--exclude=' . ltrim(BACKUP_DIR, '/')];
        foreach ($excludes as $e) {
            $cmd[] = "--exclude=$e";
        }
        array_push($cmd, '-C', '/', $path);
        $out = [];
        exec(implode(' ', array_map('escapeshellarg', $cmd)) . ' 2>&1', $out, $rc);
        if ($rc <= 1 && is_file("$f.tmp")) {   // 1 = some files changed while being read: the archive is still usable
            chmod("$f.tmp", 0600);
            rename("$f.tmp", $f);
            $items[] = ['kind' => 'files', 'name' => $name, 'path' => "/$path", 'file' => "files/$name.tar.gz", 'size' => filesize($f), 'sha256' => hash_file('sha256', $f)];
            echo "fichiers $name (/$path) : " . human_bytes(filesize($f)) . "\n";
        } else {
            @unlink("$f.tmp");
            $errors[] = "fichiers $name : " . implode(' ', array_slice($out, -3));
        }
    }
}

/** Expected size of the file archives: the last ones made, else the raw size of the folders. */
function backup_files_estimate(array $sources, array $excludes, string $today): int
{
    $days = glob(BACKUP_DIR . '/????-??-??', GLOB_ONLYDIR) ?: [];
    rsort($days);
    foreach ($days as $d) {
        $sizes = array_map('filesize', glob("$d/files/*.tar.gz") ?: []);
        if ($sizes && basename($d) !== $today) {
            return (int) array_sum($sizes);
        }
    }
    $cmd = 'du -sbc ' . implode(' ', array_map(fn($e) => escapeshellarg("--exclude=$e"), $excludes)) . ' '
        . implode(' ', array_map(fn($p) => escapeshellarg("/$p"), $sources)) . ' 2>/dev/null | tail -n1';
    return (int) sh($cmd);
}

/** Drops the days older than the database retention, and the file archives older than theirs. */
function backup_prune(): void
{
    $limit = date('Y-m-d', time() - backup_keep_days() * 86400);
    $filesLimit = date('Y-m-d', time() - backup_files_keep_days() * 86400);
    foreach (glob(BACKUP_DIR . '/????-??-??', GLOB_ONLYDIR) ?: [] as $d) {
        if (basename($d) < $limit) {
            exec('rm -rf -- ' . escapeshellarg($d));
        } elseif (basename($d) < $filesLimit && is_dir("$d/files")) {
            exec('rm -rf -- ' . escapeshellarg("$d/files"));
        }
    }
    foreach (glob(BACKUP_DIR . '/pre-restore/*.sql.gz') ?: [] as $f) {
        if (filemtime($f) < time() - backup_keep_days() * 86400) {
            unlink($f);
        }
    }
}

function c_backups(): void
{
    $days = [];
    $total = 0;
    foreach (glob(BACKUP_DIR . '/????-??-??', GLOB_ONLYDIR) ?: [] as $d) {
        $files = [];
        foreach (glob("$d/*.sql.gz") ?: [] as $f) {
            $files[] = ['db' => basename($f, '.sql.gz'), 'size' => filesize($f), 'mtime' => filemtime($f)];
        }
        $archives = [];
        foreach (glob("$d/files/*.tar.gz") ?: [] as $f) {
            $name = basename($f, '.tar.gz');
            $archives[] = ['name' => $name, 'path' => '/' . (BACKUP_SOURCES[$name] ?? $name), 'size' => filesize($f), 'mtime' => filemtime($f)];
        }
        $m = json_decode((string) @file_get_contents("$d/manifest.json"), true) ?: [];
        $size = array_sum(array_column($files, 'size')) + array_sum(array_column($archives, 'size'));
        $total += $size;
        $days[] = ['day' => basename($d), 'size' => $size, 'files' => $files, 'archives' => $archives,
            'finished' => $m['finished'] ?? null, 'errors' => $m['errors'] ?? []];
    }
    usort($days, fn($a, $b) => strcmp($b['day'], $a['day']));
    $pre = [];
    foreach (glob(BACKUP_DIR . '/pre-restore/*.sql.gz') ?: [] as $f) {
        $pre[] = ['file' => basename($f), 'size' => filesize($f), 'mtime' => filemtime($f)];
        $total += filesize($f);
    }
    save('backups', ['days' => $days, 'pre' => $pre, 'total' => $total, 'keep' => backup_keep_days(), 'enabled' => setting_bool('backup.enabled'),
        'files_enabled' => setting_bool('backup.files_enabled'), 'files_keep' => backup_files_keep_days(),
        'time' => setting('backup.time'), 'dir' => BACKUP_DIR, 'free' => (int) @disk_free_space(BACKUP_DIR)]);
}

/* ---------- traffic (incremental parse of the GlobalLog) ---------- */

function c_traffic(): void
{
    global $pdo;
    $logs = traffic_logs();
    if (!$logs) {
        return;
    }
    clearstatcache();
    $state = is_file(TRAFFIC_STATE) ? (json_decode((string) file_get_contents(TRAFFIC_STATE), true) ?: []) : [];
    if (isset($state['ino'])) {   // Hostkeep ≤ 2.1: one Apache log, state not keyed by file
        $state = ['/var/log/apache2/hostkeep-traffic.log' => $state];
    }
    $chunks = [];
    $newState = [];
    foreach ($logs as $log) {
        $st = $state[$log] ?? [];
        $ino = fileinode($log);
        $off = (int) ($st['off'] ?? 0);
        if (!empty($st['ino']) && $st['ino'] !== $ino) {       // rotated: finish the previous file first
            $old = $log . '.1';
            if (is_file($old) && fileinode($old) === $st['ino']) {
                $chunks[] = [$old, $off, null];
            }
            $off = 0;
        }
        if (filesize($log) < $off) {
            $off = 0;
        }
        $chunks[] = [$log, $off, $log];
        $newState[$log] = ['ino' => $ino, 'off' => $off];
    }

    $daily = $visitors = $top = [];
    $tz = new DateTimeZone(date_default_timezone_get());
    $dayCache = [];
    foreach ($chunks as [$file, $from, $current]) {
        $fh = fopen($file, 'r');
        fseek($fh, $from);
        $pos = $from;
        while (($line = fgets($fh)) !== false) {
            if (!str_ends_with($line, "\n")) {
                break;                                       // line still being written
            }
            $pos += strlen($line);
            if (!preg_match('/^(\S+) (\S+) \[([^\]]+)\] "((?:[^"\\\\]|\\\\.)*)" (\d{3}) (\d+|-) "((?:[^"\\\\]|\\\\.)*)" "((?:[^"\\\\]|\\\\.)*)"/', $line, $m)) {
                continue;
            }
            [, $host, $ip, $time, $req, $status, $bytes, $ref, $ua] = $m;
            $host = strtolower(preg_replace('/:\d+$/', '', $host));   // "$host:$port" formats
            $key = substr($time, 0, 11) . substr($time, -5);
            $day = $dayCache[$key] ??= (DateTime::createFromFormat('d/M/Y:H:i:s O', $time) ?: new DateTime())->setTimezone($tz)->format('Y-m-d');
            $status = (int) $status;
            $parts = explode(' ', $req);
            $path = mb_scrub(mb_substr(strtok($parts[1] ?? $req, '?') ?: '/', 0, 250));
            $bot = $ua === '-' || preg_match(BOT_RE, $ua);

            $d = &$daily[$day][$host];
            $d ??= ['hits' => 0, 'bytes' => 0, 's2' => 0, 's3' => 0, 's4' => 0, 's5' => 0, 'bots' => 0];
            $d['hits']++;
            $d['bytes'] += (int) $bytes;
            $d['s' . min(5, max(2, intdiv($status, 100)))]++;
            $d['bots'] += $bot ? 1 : 0;
            unset($d);

            $inc = static function (string $kind, string $item) use (&$top, $day, $host): void {
                $top[$day][$host][$kind][$item] = ($top[$day][$host][$kind][$item] ?? 0) + 1;
            };
            $inc('ip', $ip);
            if ($bot) {
                $inc('bot', mb_scrub(mb_substr(stripcslashes($ua), 0, 200)));
            } else {
                $visitors[$day][$host][hash('xxh64', $ip, true)] = true;
                if ($status < 400 && !preg_match(STATIC_RE, $path)) {
                    $inc('page', $path);
                }
                $rh = $ref !== '-' ? strtolower((string) parse_url($ref, PHP_URL_HOST)) : '';
                if ($rh !== '' && $rh !== $host) {
                    $inc('ref', mb_scrub(mb_substr($rh, 0, 200)));
                }
            }
            if ($status === 404) {
                $inc('404', $path);
            } elseif ($status >= 500) {
                $inc('5xx', $path);
            }
        }
        fclose($fh);
        if ($current !== null) {
            $newState[$current]['off'] = $pos;
        }
    }

    $pdo->beginTransaction();
    $q = $pdo->prepare('INSERT INTO traffic_daily (day, host, hits, bytes, s2, s3, s4, s5, bots) VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits), bytes = bytes + VALUES(bytes), s2 = s2 + VALUES(s2), s3 = s3 + VALUES(s3),
                                s4 = s4 + VALUES(s4), s5 = s5 + VALUES(s5), bots = bots + VALUES(bots)');
    foreach ($daily as $day => $hosts) {
        foreach ($hosts as $host => $d) {
            $q->execute([$day, $host, $d['hits'], $d['bytes'], $d['s2'], $d['s3'], $d['s4'], $d['s5'], $d['bots']]);
        }
    }
    $q = $pdo->prepare('INSERT IGNORE INTO traffic_visitors (day, host, ip_hash) VALUES (?,?,?)');
    foreach ($visitors as $day => $hosts) {
        foreach ($hosts as $host => $hashes) {
            foreach (array_keys($hashes) as $h) {
                $q->execute([$day, $host, $h]);
            }
        }
    }
    $q = $pdo->prepare('INSERT INTO traffic_top (day, host, kind, item, hits) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE hits = hits + VALUES(hits)');
    foreach ($top as $day => $hosts) {
        foreach ($hosts as $host => $kinds) {
            foreach ($kinds as $kind => $items) {
                foreach ($items as $item => $n) {
                    $q->execute([$day, $host, $kind, (string) $item, $n]);
                }
            }
        }
    }
    $pdo->commit();
    file_put_contents(TRAFFIC_STATE, json_encode($newState));
}

/* ---------- performance: memory per component, PHP pools, slow queries ---------- */

function c_perf(): void
{
    global $pdo;
    $groups = $pools = [];
    foreach (lines(sh('ps -eo rss=,comm=,args=')) as $l) {
        $f = preg_split('/\s+/', trim($l), 3);
        $rss = (int) $f[0] * 1024;
        $comm = $f[1] ?? '?';
        $args = $f[2] ?? '';
        if (preg_match('/^php-fpm: pool (\S+)/', $args, $m)) {
            $pools[$m[1]]['rss'] = ($pools[$m[1]]['rss'] ?? 0) + $rss;
            $pools[$m[1]]['procs'] = ($pools[$m[1]]['procs'] ?? 0) + 1;
            $g = 'PHP-FPM';
        } elseif (str_starts_with($args, 'php-fpm: master')) {
            $g = 'PHP-FPM';
        } elseif ($comm === 'apache2') {
            $g = 'Apache';
        } elseif ($comm === 'nginx') {
            $g = 'Nginx';
        } elseif ($comm === 'caddy') {
            $g = 'Caddy';
        } elseif (in_array($comm, ['mariadbd', 'mysqld'], true)) {
            $g = 'MariaDB';
        } elseif ($comm === 'node' || str_contains($args, 'PM2')) {
            $g = 'Node / PM2';
        } elseif (str_contains($args, '.claude/remote')) {
            $g = 'Claude (accès distant)';
        } else {
            $g = $comm;
        }
        $groups[$g] = ($groups[$g] ?? 0) + $rss;
    }
    arsort($groups);
    $mem = [];
    foreach (array_slice($groups, 0, 9, true) as $k => $v) {
        $mem[] = ['name' => $k, 'rss' => $v];
    }
    $rest = array_sum(array_slice($groups, 9));
    if ($rest) {
        $mem[] = ['name' => 'Autres', 'rss' => $rest];
    }

    // pool settings and the sites each pool serves
    $sockets = [];
    foreach (snapshot_data('sites')['sites'] ?? [] as $site) {   // both servers: sockets found in the vhosts
        foreach ($site['sockets'] ?? [] as $sock) {
            $sockets[$sock][] = $site['name'];
        }
    }
    $fpmLog = (string) @file_get_contents('/var/log/php' . php_version() . '-fpm.log');
    $conf = [];
    foreach (glob('/etc/php/' . php_version() . '/fpm/pool.d/*.conf') ?: [] as $f) {
        $name = null;
        foreach (file($f) as $l) {
            if (preg_match('/^\s*\[([^\]]+)\]/', $l, $m)) {
                $name = $m[1];
            } elseif ($name && preg_match('/^\s*(user|listen|pm|pm\.max_children)\s*=\s*(\S+)/', $l, $m)) {
                $conf[$name][$m[1]] = $m[2];
            }
        }
    }
    $poolRows = [];
    foreach ($conf as $name => $c) {
        $sites = array_values(array_unique($sockets[$c['listen'] ?? ''] ?? []));
        if (($c['listen'] ?? '') === '/run/php/php' . php_version() . '-fpm.sock') {
            $sites[] = 'tous les autres sites PHP';
        }
        preg_match_all('/\[pool ' . preg_quote($name, '/') . '\] server reached pm\.max_children/', $fpmLog, $w);
        $poolRows[] = ['name' => $name, 'user' => $c['user'] ?? '?', 'pm' => $c['pm'] ?? '?', 'max_children' => (int) ($c['pm.max_children'] ?? 0),
            'procs' => $pools[$name]['procs'] ?? 0, 'rss' => $pools[$name]['rss'] ?? 0, 'max_reached' => count($w[0]), 'sites' => $sites];
    }

    // MariaDB slow query log
    $vars = [];
    foreach ($pdo->query("SHOW GLOBAL VARIABLES WHERE Variable_name IN ('slow_query_log','long_query_time','slow_query_log_file','datadir','max_connections','innodb_buffer_pool_size')") as $r) {
        $vars[$r['Variable_name']] = $r['Value'];
    }
    $slowFile = $vars['slow_query_log_file'] ?? '';
    if ($slowFile !== '' && $slowFile[0] !== '/') {
        $slowFile = rtrim($vars['datadir'] ?? '/var/lib/mysql', '/') . '/' . $slowFile;
    }
    $queries = [];
    if (is_file($slowFile)) {
        $size = filesize($slowFile);
        $txt = (string) file_get_contents($slowFile, false, null, max(0, $size - 2_000_000));
        foreach (preg_split('/^# (?:Time|User@Host): /m', $txt) as $block) {
            if (!preg_match('/# Query_time: ([\d.]+)\s+Lock_time: [\d.]+\s+Rows_sent: (\d+)\s+Rows_examined: (\d+)/', $block, $q)) {
                continue;
            }
            $schema = preg_match('/Schema: (\S+)/', $block, $s) ? $s[1] : '';
            $sql = trim(implode("\n", array_filter(explode("\n", $block), fn($l) => $l !== '' && $l[0] !== '#' && !preg_match('/^(SET timestamp=|use \S+;$)/i', $l))));
            if ($sql === '') {
                continue;
            }
            $norm = preg_replace(['/\'(?:[^\'\\\\]|\\\\.)*\'/', '/"(?:[^"\\\\]|\\\\.)*"/', '/\b\d+(\.\d+)?\b/', '/\(\s*\?(?:\s*,\s*\?)*\s*\)/', '/\s+/'], ['?', '?', '?', '(?)', ' '], $sql);
            $norm = mb_scrub(mb_substr($norm, 0, 400));
            $e = &$queries[$schema . '|' . $norm];
            $e ??= ['db' => $schema, 'query' => $norm, 'example' => mb_scrub(mb_substr($sql, 0, 800)), 'count' => 0, 'total' => 0.0, 'max' => 0.0, 'rows' => 0];
            $e['count']++;
            $e['total'] += (float) $q[1];
            $e['max'] = max($e['max'], (float) $q[1]);
            $e['rows'] = max($e['rows'], (int) $q[3]);
            unset($e);
        }
        usort($queries, fn($a, $b) => $b['total'] <=> $a['total']);
    }
    $slowCount = (int) ($pdo->query("SHOW GLOBAL STATUS LIKE 'Slow_queries'")->fetch()['Value'] ?? 0);
    save('perf', ['memory' => $mem, 'pools' => $poolRows, 'mariadb' => $vars + ['Slow_queries' => $slowCount],
        'slow' => array_slice(array_values($queries), 0, 25)]);
}

/* ---------- security scan ---------- */

function http_probe(string $url, int $max = 16384): array
{
    $body = '';
    $headers = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT => 'hostkeep-security-scan',
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$headers) {
            $p = explode(':', $h, 2);
            if (count($p) === 2) {
                $headers[strtolower(trim($p[0]))] = trim($p[1]);
            }
            return strlen($h);
        },
        CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$body, $max) {
            $body .= $d;
            return strlen($body) >= $max ? 0 : strlen($d);
        },
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => substr($body, 0, $max), 'headers' => $headers];
}

function is_html(string $b): bool
{
    return (bool) preg_match('/<(!doctype|html|head|body)\b/i', substr($b, 0, 2000));
}

/** Files on disk that should never be downloadable, relative to the document root. */
function risky_files(string $root): array
{
    $found = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            fn($f) => !($f->isDir() && in_array($f->getFilename(), ['node_modules', 'vendor', 'cache', 'var'], true))),
        RecursiveIteratorIterator::SELF_FIRST);
    $it->setMaxDepth(4);
    foreach ($it as $f) {
        $n = $f->getFilename();
        if (($f->isDir() && $n === '.git') || ($f->isFile() && preg_match('/^\.env(\..+)?$|\.(sql|sql\.gz|bak|old|orig|swp|zip|tar|tar\.gz|tgz|7z|rar|log)$|~$|^(phpinfo|info|test)\.php$/i', $n))) {
            $found[] = substr($f->getPathname(), strlen(rtrim($root, '/')));
            if (count($found) >= 40) {
                break;
            }
        }
    }
    return $found;
}

function c_scan(): void
{
    $t0 = time();
    $findings = [];
    $add = function (string $sev, string $site, string $title, string $detail = '') use (&$findings): void {
        $findings[] = ['severity' => $sev, 'site' => $site, 'title' => $title, 'detail' => $detail];
    };

    // server level
    // A real firewall = INPUT policy drop (fail2ban's own REJECT rules do not count).
    $firewall = (int) trim(sh('{ iptables -S INPUT 2>/dev/null | grep "^-P INPUT DROP"; nft list ruleset 2>/dev/null | grep -E "hook input.*policy drop"; ufw status 2>/dev/null | grep -i "Status: active"; } | wc -l'));
    foreach (lines(sh('ss -tlnpH')) as $l) {
        $f = preg_split('/\s+/', $l);
        $addr = $f[3] ?? '';
        $port = (int) substr($addr, strrpos($addr, ':') + 1);
        $local = preg_match('/^(127\.|\[::1\]|\[::ffff:127\.)/', $addr);
        if (!$local && !in_array($port, [22, 80, 443], true)) {
            $proc = preg_match('/users:\(\("([^"]+)"/', $l, $m) ? $m[1] : '?';
            $add($firewall ? 'info' : 'warn', 'serveur', "Port $port ($proc) en écoute sur Internet",
                $firewall ? "Adresse $addr. Un pare-feu nftables est présent : vérifie qu'il bloque ce port."
                    : "Adresse $addr et aucun pare-feu : le port est joignable de partout. À restreindre à 127.0.0.1 si le service n'a pas besoin d'être public.");
        }
    }
    $sshd = sh('sshd -T');
    if (preg_match('/^passwordauthentication yes/m', $sshd)) {
        $add('warn', 'serveur', 'Connexion SSH par mot de passe autorisée', 'fail2ban protège sshd, mais les clés seules (PasswordAuthentication no) sont plus sûres.');
    }
    if (preg_match('/^permitrootlogin (yes|without-password|prohibit-password)/m', $sshd, $m) && $m[1] === 'yes') {
        $add('warn', 'serveur', 'Connexion SSH root par mot de passe autorisée');
    }
    $ngx = implode("\n", nginx_dump());
    if ($ngx !== '' && !preg_match('/^\s*server_tokens\s+off\s*;/m', $ngx)) {
        $add('info', 'serveur', 'Nginx affiche sa version (server_tokens)', 'Ajoute « server_tokens off; » dans le bloc http de /etc/nginx/nginx.conf.');
    }
    // Apache: what it really sends (a later conf, e.g. Hostkeep's zz-hostkeep-tokens.conf, can override security.conf)
    if (is_dir('/etc/apache2/conf-enabled') && trim(sh('systemctl is-active apache2')) === 'active') {
        $port = trim(sh("ss -Hltnp 'sport = :80' | grep -q apache2 && echo 80"));
        $server = $port !== '' ? (http_probe('http://127.0.0.1/', 1024)['headers']['server'] ?? '') : '';
        if (preg_match('#Apache/\d#', $server)) {
            $add('info', 'serveur', "Apache affiche sa version ($server)", 'ServerTokens Prod et ServerSignature Off la masquent.');
        } elseif ($server === '') {
            $sec = (string) @file_get_contents('/etc/apache2/conf-enabled/security.conf');
            if (preg_match('/^\s*ServerTokens\s+(OS|Full|Minor|Minimal|Major)/mi', $sec, $m) && !file_exists('/etc/apache2/conf-enabled/zz-hostkeep-tokens.conf')) {
                $add('info', 'serveur', "Apache affiche sa version (ServerTokens {$m[1]})", 'ServerTokens Prod et ServerSignature Off la masquent.');
            }
        }
    }
    foreach (glob('/etc/sudoers.d/*') ?: [] as $f) {
        if (is_file($f) && (fileperms($f) & 0777) !== 0440) {
            $add('info', 'serveur', 'Droits incorrects sur ' . $f, sprintf('Mode %o au lieu de 440 (signalé par visudo -c).', fileperms($f) & 0777));
        }
    }

    // per site
    $sites = snapshot_data('sites')['sites'] ?? [];
    $seenRoots = [];
    foreach ($sites as $s) {
        $name = $s['name'];
        $base = rtrim($s['url'], '/');
        $home = http_probe($base . '/', 65536);
        $fallback = http_probe($base . '/hostkeep-scan-' . bin2hex(random_bytes(4)) . '.txt');
        $isFallback = fn(array $r) => $r['code'] === 200 && $fallback['code'] === 200 && substr($r['body'], 0, 2000) === substr($fallback['body'], 0, 2000);

        $probes = [
            ['/.env', 'crit', 'Fichier .env téléchargeable', fn($b) => preg_match('/^[A-Z][A-Z0-9_]*=/m', $b) && !is_html($b)],
            ['/.git/HEAD', 'crit', 'Dépôt .git téléchargeable', fn($b) => (bool) preg_match('/^(ref: |[0-9a-f]{40}\s*$)/', ltrim($b))],
            ['/.git/config', 'crit', 'Configuration .git téléchargeable', fn($b) => str_contains($b, '[core]')],
            ['/.htaccess', 'warn', '.htaccess lisible', fn($b) => !is_html($b) && preg_match('/Rewrite|Require|Deny|Options|php_value/i', $b)],
            ['/phpinfo.php', 'warn', 'phpinfo() public', fn($b) => str_contains($b, 'PHP License') || str_contains($b, 'phpinfo()')],
            ['/info.php', 'warn', 'phpinfo() public', fn($b) => str_contains($b, 'PHP License') || str_contains($b, 'phpinfo()')],
            ['/composer.json', 'info', 'composer.json lisible (liste des dépendances)', fn($b) => str_contains($b, '"require"') && !is_html($b)],
        ];
        foreach (['/sql/', '/backup/', '/backups/', '/uploads/', '/vendor/', '/data/', '/images/', '/img/'] as $d) {
            $probes[] = [$d, in_array($d, ['/sql/', '/backup/', '/backups/'], true) ? 'crit' : 'warn', "Liste du dossier $d visible", fn($b) => str_contains($b, '<title>Index of /')];
        }
        foreach ($probes as [$path, $sev, $title, $test]) {
            $r = http_probe($base . $path);
            if ($r['code'] === 200 && !$isFallback($r) && $test($r['body'])) {
                $add($sev, $name, $title, $base . $path);
            }
        }
        // files on disk that the web server would hand out
        $root = $s['docroot'] ?? null;
        if ($root && is_dir($root) && !isset($seenRoots[$root . $name])) {
            $seenRoots[$root . $name] = true;
            foreach (risky_files($root) as $rel) {
                $url = $base . implode('/', array_map('rawurlencode', explode('/', $rel)));
                $r = http_probe($url, 4096);
                if ($r['code'] === 200 && !$isFallback($r)) {
                    $sev = preg_match('/\.env|\.git|\.sql|\.bak|\.zip|\.tar|\.tgz|\.7z|\.rar/i', $rel) ? 'crit' : 'warn';
                    $add($sev, $name, 'Fichier sensible téléchargeable : ' . $rel, $url);
                }
            }
        }
        // headers
        if ($home['code'] > 0) {
            $h = $home['headers'];
            $missing = [];
            if (str_starts_with($base, 'https') && !isset($h['strict-transport-security'])) {
                $missing[] = 'Strict-Transport-Security';
            }
            if (!isset($h['x-content-type-options'])) {
                $missing[] = 'X-Content-Type-Options';
            }
            if (!isset($h['x-frame-options']) && !str_contains($h['content-security-policy'] ?? '', 'frame-ancestors')) {
                $missing[] = 'X-Frame-Options';
            }
            if (!isset($h['referrer-policy'])) {
                $missing[] = 'Referrer-Policy';
            }
            if ($missing) {
                $add('info', $name, 'En-têtes de sécurité manquants', implode(', ', $missing));
            }
            if (isset($h['x-powered-by'])) {
                $add('info', $name, 'X-Powered-By révèle la techno', $h['x-powered-by']);
            }
        }
        if (str_starts_with($base, 'https')) {
            $plain = http_probe('http://' . $name . '/');
            if ($plain['code'] === 200) {
                $add('warn', $name, 'Pas de redirection HTTP → HTTPS', 'http://' . $name . '/ répond 200 en clair.');
            }
        }
    }
    $order = ['crit' => 0, 'warn' => 1, 'info' => 2];
    usort($findings, fn($a, $b) => [$order[$a['severity']], $a['site']] <=> [$order[$b['severity']], $b['site']]);
    save('secscan', ['ran' => time(), 'duration' => time() - $t0, 'sites' => count($sites), 'findings' => $findings, 'hardening' => hardening_state()]);
    echo count($findings) . " point(s) relevé(s) sur " . count($sites) . " site(s).\n";
}

/* ---------- audit fixes (Sécurité › Audit › Corriger, root helper « harden ») ---------- */

/** Which global fix is in place, per web server. */
function hardening_state(): array
{
    $apache = is_dir('/etc/apache2/conf-enabled');
    $nginx = is_dir('/etc/nginx/conf.d');
    return [
        'servers' => ['apache' => $apache, 'nginx' => $nginx],
        'headers' => file_exists('/etc/apache2/conf-enabled/hostkeep-headers.conf') || file_exists('/etc/nginx/conf.d/hostkeep-security-headers.conf'),
        'tokens' => file_exists('/etc/apache2/conf-enabled/zz-hostkeep-tokens.conf') || file_exists('/etc/nginx/conf.d/hostkeep-tokens.conf'),
        'sensitive' => file_exists('/etc/apache2/conf-enabled/hardening.conf') || file_exists('/etc/nginx/snippets/hostkeep-deny.conf'),
    ];
}

/** Which audit findings a fix addresses (same rules as the « Corriger » buttons of the page). */
function finding_fix(array $f): ?string
{
    return match (true) {
        $f['title'] === 'En-têtes de sécurité manquants' => 'headers',
        (bool) preg_match('/affiche sa version/', $f['title']) => 'tokens',
        (bool) preg_match('/téléchargeable|lisible|Liste du dossier|phpinfo/', $f['title']) => 'sensitive',
        default => null,
    };
}

/** After a fix: what the new audit still finds of that kind, and what to add by hand for Nginx. */
function c_harden_report(): void
{
    global $argv;
    $kind = $argv[2] ?? '';
    $mode = $argv[3] ?? 'on';
    $left = array_values(array_filter(snapshot_data('secscan')['findings'] ?? [], fn($f) => finding_fix($f) === $kind));
    if ($mode === 'off') {
        echo "Correction retirée.\n";
        return;
    }
    if (!$left) {
        echo "✔ Le nouvel audit ne trouve plus aucun constat de ce type.\n";
        return;
    }
    $sites = [];
    foreach (snapshot_data('sites')['sites'] ?? [] as $s) {
        $sites[$s['name']] = $s;
    }
    echo count($left) . " constat(s) de ce type restent :\n";
    $hints = [];
    foreach ($left as $f) {
        echo "  - {$f['site']} : {$f['title']}" . ($f['detail'] !== '' ? " ({$f['detail']})" : '') . "\n";
        $s = $sites[$f['site']] ?? null;
        if ($s && ($s['server'] ?? '') === 'nginx' && in_array($kind, ['headers', 'sensitive'], true)) {
            $files = array_map(fn($c) => preg_replace('/^nginx:/', '', $c), array_filter($s['confs'] ?? [], fn($c) => str_starts_with($c, 'nginx:')));
            $hints[$f['site'] . ' → fichier ' . (implode(', ', $files) ?: '?') . ' (dans /etc/nginx/)'] = true;
        }
    }
    if ($hints) {
        $line = $kind === 'headers' ? 'include snippets/hostkeep-headers.conf;' : 'include snippets/hostkeep-deny.conf;';
        echo "\nNginx ne peut pas appliquer ceci à tous les sites d'un coup. Ajoute la ligne\n    $line\n"
            . "en haut du bloc server de chaque site concerné, puis « nginx -t && systemctl reload nginx » :\n";
        foreach (array_keys($hints) as $h) {
            echo "  - $h\n";
        }
    } elseif ($kind === 'headers') {
        echo "\nCes sites envoient sans doute déjà certains en-têtes eux-mêmes (application, .htaccess) : Hostkeep ne les remplace jamais.\n";
    }
}

function snapshot_data(string $name): array
{
    global $pdo;
    $s = $pdo->prepare('SELECT data FROM snapshots WHERE name = ?');
    $s->execute([$name]);
    return json_decode((string) $s->fetchColumn(), true) ?: [];
}
