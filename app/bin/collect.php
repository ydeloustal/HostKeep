<?php
declare(strict_types=1);

/**
 * Collector, run as root by /etc/cron.d/hostkeep:
 *   minute : metrics, services, processes
 *   5min   : sites (HTTP checks), certificates, PM2, security, databases
 *   hourly : storage, cron, pending updates, list of logs
 *   purge  : drop old rows and job files
 *   all    : everything (used after install and by the "collect-now" action)
 * Only fixed commands are run here: nothing comes from the web.
 */

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    fwrite(STDERR, "À lancer en root, en ligne de commande.\n");
    exit(1);
}
require __DIR__ . '/../src/live.php';
require __DIR__ . '/../src/collect_extra.php';

const STATE = '/var/lib/hostkeep/state.json';
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
putenv('HOME=/root');
putenv('PM2_HOME=/root/.pm2');
putenv('LC_ALL=C');

$mode = $argv[1] ?? 'minute';
$modes = ['minute' => ['metrics', 'services', 'processes'],
    '5min' => ['sites', 'certs', 'pm2', 'security', 'databases', 'traffic', 'perf'],
    'hourly' => ['storage', 'cron', 'updates', 'logkeys', 'backups'],
    'purge' => ['purge'],
    'backup' => ['backup', 'backups'],     // nightly database dumps
    'scan' => ['scan'],                    // nightly security scan of the sites
    'updates' => ['updates'],              // after apt update / upgrade
    'backup-list' => ['backups']];         // after a restore
$modes['all'] = array_merge($modes['minute'], $modes['5min'], $modes['hourly']);
if (!isset($modes[$mode])) {
    fwrite(STDERR, "Mode inconnu : $mode\n");
    exit(1);
}

$lock = fopen("/var/lib/hostkeep/collect-$mode.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // previous run still going
}

$pdo = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;dbname=hostkeep;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

function sh(string $cmd): string
{
    return (string) shell_exec($cmd . ' 2>/dev/null');
}

function lines(string $s): array
{
    return array_values(array_filter(array_map('rtrim', explode("\n", $s)), 'strlen'));
}

function save(string $name, array $data): void
{
    global $pdo;
    $pdo->prepare('REPLACE INTO snapshots (name, data, updated) VALUES (?, ?, ?)')
        ->execute([$name, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), time()]);
}

function strip_comments(string $s): string
{
    return implode("\n", array_filter(lines($s), fn($l) => !preg_match('/^\s*#/', $l)));
}

/* ---------- minute ---------- */

function c_metrics(): void
{
    global $pdo;
    $now = time();
    $state = is_file(STATE) ? json_decode((string) file_get_contents(STATE), true) : null;
    $cpu = cpu_sample();
    $net = net_bytes();
    if (!$state || $now - $state['ts'] > 600 || $now <= $state['ts']) {
        $state = ['ts' => $now - 1, 'cpu' => $cpu, 'net' => $net];
        sleep(1);
        $cpu = cpu_sample();
        $net = net_bytes();
        $now = time();
    }
    $dt = max(1, $now - $state['ts']);
    $m = meminfo();
    $l = loadavg();
    [$dTotal, $dUsed] = array_map('intval', preg_split('/\s+/', trim(lines(sh('df -B1 --output=size,used /'))[1] ?? '0 0')));
    $pdo->prepare('INSERT IGNORE INTO metrics VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
        intdiv($now, 60) * 60, cpu_percent($state['cpu'], $cpu), $l['load1'], $l['load5'], $l['load15'],
        $m['MemTotal'] ?? 0, $m['MemAvailable'] ?? 0, $m['SwapTotal'] ?? 0, ($m['SwapTotal'] ?? 0) - ($m['SwapFree'] ?? 0),
        $dTotal, $dUsed, max(0, ($net[0] - $state['net'][0]) / $dt), max(0, ($net[1] - $state['net'][1]) / $dt), $l['procs'],
    ]);
    file_put_contents(STATE, json_encode(['ts' => $now, 'cpu' => $cpu, 'net' => $net]));
}

function c_services(): void
{
    $pm2 = lines(sh("systemctl list-units --type=service --all --no-legend --plain 'pm2-*'"));
    $units = ['apache2' => 'apache2', 'php8.4-fpm' => 'php8.4-fpm', 'mariadb' => 'mariadb', 'cron' => 'cron',
        'fail2ban' => 'fail2ban', 'postfix@-' => 'postfix', 'ssh' => null];
    foreach ($pm2 as $l) {
        $units[preg_replace('/\.service$/', '', strtok($l, ' '))] = null;
    }
    $out = [];
    foreach ($units as $unit => $action) {
        $p = ['Id' => "$unit.service"];
        foreach (lines(sh('systemctl show ' . escapeshellarg("$unit.service") . ' -p Id,Description,ActiveState,SubState,MainPID,MemoryCurrent,ActiveEnterTimestamp,NRestarts')) as $kv) {
            [$k, $v] = explode('=', $kv, 2) + [1 => ''];
            $p[$k] = $v;
        }
        $p['action'] = $action;
        $p['reload'] = in_array($action, ['apache2', 'php8.4-fpm', 'fail2ban', 'postfix', 'mariadb'], true);
        $out[] = $p;
    }
    $failed = array_map(fn($l) => strtok($l, ' '), lines(sh('systemctl --failed --no-legend --plain')));
    save('services', ['units' => $out, 'failed' => $failed]);
}

function c_processes(): void
{
    $parse = function (string $sort): array {
        $rows = [];
        foreach (lines(sh("ps -eo pid=,user:20=,pcpu=,pmem=,rss=,etimes=,args= --sort=$sort | head -15")) as $l) {
            $f = preg_split('/\s+/', trim($l), 7);
            $rows[] = ['pid' => (int) $f[0], 'user' => $f[1], 'cpu' => (float) $f[2], 'mem' => (float) $f[3],
                'rss' => (int) $f[4], 'etime' => (int) $f[5], 'cmd' => mb_strimwidth($f[6] ?? '', 0, 160, '…')];
        }
        return $rows;
    };
    save('processes', ['by_mem' => $parse('-rss'), 'by_cpu' => $parse('-pcpu')]);
}

/* ---------- 5 min ---------- */

function cert_end(string $file): ?int
{
    static $cache = [];
    if (!array_key_exists($file, $cache)) {
        $o = sh('openssl x509 -noout -enddate -in ' . escapeshellarg($file));
        $cache[$file] = preg_match('/notAfter=(.+)/', $o, $x) ? (strtotime(trim($x[1])) ?: null) : null;
    }
    return $cache[$file];
}

function vhost_block(string $file, int $line): array
{
    $l = @file($file) ?: [];
    $r = ['docroot' => null, 'cert' => null];
    for ($i = max(0, $line - 1), $n = count($l); $i < $n; $i++) {
        if (preg_match('#^\s*</VirtualHost#i', $l[$i])) {
            break;
        }
        if (preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)/i', $l[$i], $x)) {
            $r['docroot'] = $x[1];
        }
        if (preg_match('/^\s*SSLCertificateFile\s+"?([^"\s]+)/i', $l[$i], $x)) {
            $r['cert'] = $x[1];
        }
    }
    return $r;
}

function c_sites(): void
{
    global $pdo;
    $sites = [];
    foreach (lines(sh('apache2ctl -S')) as $l) {
        if (preg_match('/port (\d+) namevhost (\S+) \((.+?):(\d+)\)/', $l, $x)) {
            [, $port, $name, $file, $line] = $x;
            unset($last);   // break the reference: assigning null would overwrite the previous site
            if (preg_match('/^(\d+\.){3}\d+$|^localhost$|:/', $name)) {
                continue;
            }
            $s = &$sites[$name];
            $s['name'] = $name;
            $s['ports'][] = (int) $port;
            $conf = basename((string) (realpath($file) ?: $file), '.conf');
            $s['confs'][] = $conf;
            $b = vhost_block($file, (int) $line);
            $s['docroot'] ??= $b['docroot'];
            if ($b['cert']) {
                $s['cert'] = $b['cert'];
            }
            $s['aliases'] ??= [];
            $last = &$s;
            unset($s);
        } elseif (isset($last) && preg_match('/^\s+alias (\S+)/', $l, $x) && !preg_match('/^(\d+\.){3}\d+$|^localhost$/', $x[1])) {
            $last['aliases'][] = $x[1];
        }
    }
    unset($last);
    $now = time();
    $ins = $pdo->prepare('INSERT INTO site_checks (ts, host, code, ms) VALUES (?, ?, ?, ?)');
    foreach ($sites as &$s) {
        $s['confs'] = array_values(array_unique($s['confs']));
        $s['aliases'] = array_values(array_unique($s['aliases']));
        $https = in_array(443, $s['ports'], true);
        $s['url'] = ($https ? 'https://' : 'http://') . $s['name'] . '/';
        $o = sh('curl -s -o /dev/null --max-time 8 -w "%{http_code} %{time_total}" ' . escapeshellarg($s['url']));
        [$code, $t] = explode(' ', trim($o) ?: '0 0') + [1 => '0'];
        $s['code'] = (int) $code;
        $s['ms'] = (int) round((float) $t * 1000);
        $end = !empty($s['cert']) ? cert_end($s['cert']) : null;
        $s['cert_days'] = $end ? (int) floor(($end - $now) / 86400) : null;
        unset($s['cert']);
        $ins->execute([$now, $s['name'], $s['code'], $s['ms']]);
    }
    unset($s);
    ksort($sites);

    $enabled = array_map(fn($f) => basename((string) (realpath($f) ?: $f), '.conf'), glob('/etc/apache2/sites-enabled/*.conf') ?: []);
    $available = array_map(fn($f) => basename($f, '.conf'), glob('/etc/apache2/sites-available/*.conf') ?: []);
    save('sites', ['sites' => array_values($sites), 'disabled' => array_values(array_diff($available, $enabled)), 'self' => 'hostkeep']);
}

function c_certs(): void
{
    $now = time();
    $certs = [];
    foreach (glob('/etc/letsencrypt/live/*/cert.pem') ?: [] as $f) {
        $o = sh('openssl x509 -noout -enddate -ext subjectAltName -in ' . escapeshellarg($f));
        preg_match('/notAfter=(.+)/', $o, $x);
        preg_match_all('/DNS:([^,\s]+)/', $o, $d);
        $end = isset($x[1]) ? (strtotime(trim($x[1])) ?: 0) : 0;
        $certs[] = ['name' => basename(dirname($f)), 'domains' => $d[1], 'expires' => $end, 'days' => (int) floor(($end - $now) / 86400)];
    }
    usort($certs, fn($a, $b) => $a['days'] <=> $b['days']);
    save('certs', ['certs' => $certs]);
}

function c_pm2(): void
{
    $apps = [];
    foreach (json_decode(sh('pm2 jlist'), true) ?: [] as $p) {
        $apps[] = ['name' => $p['name'], 'status' => $p['pm2_env']['status'] ?? '?', 'cpu' => $p['monit']['cpu'] ?? 0,
            'memory' => $p['monit']['memory'] ?? 0, 'restarts' => $p['pm2_env']['restart_time'] ?? 0, 'uptime' => $p['pm2_env']['pm_uptime'] ?? null];
    }
    save('pm2', ['apps' => $apps]);
}

function c_security(): void
{
    $jails = [];
    if (preg_match('/Jail list:\s*(.*)/', sh('fail2ban-client status'), $x)) {
        foreach (array_filter(array_map('trim', explode(',', $x[1]))) as $j) {
            $o = sh('fail2ban-client status ' . escapeshellarg($j));
            $g = fn($k) => preg_match("/$k:\s*(.*)/", $o, $m) ? trim($m[1]) : '';
            $jails[] = ['name' => $j, 'failed_now' => (int) $g('Currently failed'), 'failed_total' => (int) $g('Total failed'),
                'banned_now' => (int) $g('Currently banned'), 'banned_total' => (int) $g('Total banned'),
                'banned' => array_values(array_filter(preg_split('/\s+/', $g('Banned IP list'))))];
        }
    }
    $listening = [];
    foreach (lines(sh('ss -tulnpH')) as $l) {
        $f = preg_split('/\s+/', $l);
        $proc = preg_match('/users:\(\("([^"]+)"/', $l, $m) ? $m[1] : '';
        $listening[$f[0] . ' ' . $f[4]] = ['addr' => strtoupper($f[0]) . ' ' . $f[4], 'proc' => $proc];
    }
    ksort($listening);
    $running = php_uname('r');
    $kernels = lines(sh('ls /boot | sed -n "s/^vmlinuz-//p" | sort -V'));
    $latest = end($kernels) ?: $running;
    save('security', [
        'jails' => $jails,
        'listening' => array_values($listening),
        'logins' => array_slice(lines(sh('journalctl -u ssh --since "-7 days" --no-pager -o short-iso | grep -E "Accepted "')), -20),
        'kernel_running' => $running,
        'kernel_latest' => $latest,
        'reboot_required' => is_file('/run/reboot-required') || $latest !== $running,
    ]);
}

function c_databases(): void
{
    global $pdo;
    $dbs = $pdo->query("SELECT table_schema AS name, COUNT(*) AS tables, COALESCE(SUM(table_rows), 0) AS `rows`,
                               COALESCE(SUM(data_length + index_length), 0) AS size
                        FROM information_schema.tables
                        WHERE table_schema NOT IN ('information_schema', 'performance_schema', 'sys')
                        GROUP BY table_schema ORDER BY size DESC")->fetchAll();
    $status = [];
    foreach ($pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Threads_connected','Questions','Max_used_connections','Slow_queries','Aborted_connects')") as $r) {
        $status[$r['Variable_name']] = (int) $r['Value'];
    }
    foreach ($dbs as &$d) {
        $d['tables'] = (int) $d['tables'];
        $d['rows'] = (int) $d['rows'];
        $d['size'] = (int) $d['size'];
    }
    unset($d);
    save('databases', ['version' => $pdo->query('SELECT VERSION()')->fetchColumn(), 'status' => $status, 'dbs' => $dbs]);
}

/* ---------- hourly ---------- */

function c_storage(): void
{
    $df = [];
    foreach (array_slice(lines(sh('df -B1 -x tmpfs -x devtmpfs -x squashfs -x efivarfs --output=source,fstype,size,used,avail,pcent,target')), 1) as $l) {
        $f = preg_split('/\s+/', $l, 7);
        $df[] = ['source' => $f[0], 'fstype' => $f[1], 'size' => (int) $f[2], 'used' => (int) $f[3], 'avail' => (int) $f[4], 'pct' => (int) $f[5], 'target' => $f[6]];
    }
    $dirs = [];
    foreach (lines(sh('du -sxb /var/www/* /home/* /root /var/log /var/lib/mysql /var/cache/apt /var/lib/apt/lists /usr /opt /tmp')) as $l) {
        [$size, $path] = preg_split('/\t/', $l, 2);
        $dirs[] = ['path' => $path, 'size' => (int) $size];
    }
    usort($dirs, fn($a, $b) => $b['size'] <=> $a['size']);
    $journal = (int) trim(sh('du -sxb /var/log/journal | cut -f1'));
    $apt = (int) trim(sh('du -sxb /var/cache/apt | cut -f1'));
    save('storage', ['df' => $df, 'dirs' => array_slice($dirs, 0, 30), 'journal' => $journal, 'apt_cache' => $apt]);
}

function c_cron(): void
{
    $tabs = [];
    foreach (['root', 'mmi24c04', 'deploy', 'www-data'] as $u) {
        $t = strip_comments(sh('crontab -l -u ' . escapeshellarg($u)));
        if ($t !== '') {
            $tabs[$u] = $t;
        }
    }
    $crond = [];
    foreach (glob('/etc/cron.d/*') ?: [] as $f) {
        if (is_file($f) && !str_starts_with(basename($f), '.')) {
            $crond[basename($f)] = strip_comments((string) file_get_contents($f));
        }
    }
    $timers = [];
    foreach (json_decode(sh('systemctl list-timers --all -o json'), true) ?: [] as $t) {
        $timers[] = ['next' => !empty($t['next']) ? date('d/m H:i', intdiv((int) $t['next'], 1000000)) : '–', 'unit' => $t['unit'] ?? '', 'activates' => $t['activates'] ?? ''];
    }
    save('cron', ['crontabs' => $tabs, 'crond' => $crond, 'timers' => $timers]);
}

function c_updates(): void
{
    $pk = [];
    foreach (lines(sh('apt list --upgradable')) as $l) {
        if (preg_match('#^([^/]+)/(\S+)\s+(\S+)\s+\S+(?:\s+\[upgradable from: ([^\]]+)\])?#', $l, $x)) {
            $pk[] = ['name' => $x[1], 'suite' => $x[2], 'version' => $x[3], 'from' => $x[4] ?? '', 'security' => str_contains($x[2], 'security')];
        }
    }
    save('updates', ['count' => count($pk), 'security_count' => count(array_filter($pk, fn($p) => $p['security'])), 'packages' => $pk]);
}

function c_logkeys(): void
{
    $keys = [['key' => 'journal-errors', 'label' => 'systemd — erreurs récentes', 'size' => null],
        ['key' => 'fail2ban', 'label' => 'fail2ban', 'size' => @filesize('/var/log/fail2ban.log') ?: null],
        ['key' => 'hostkeep-auth', 'label' => 'Hostkeep — échecs de connexion', 'size' => @filesize('/var/log/hostkeep/auth.log') ?: null]];
    foreach (glob('/var/log/apache2/*.log') ?: [] as $f) {
        $b = basename($f);
        if (preg_match('/^[A-Za-z0-9._-]+$/', $b)) {
            $keys[] = ['key' => "apache:$b", 'label' => "Apache — $b", 'size' => filesize($f)];
        }
    }
    foreach (json_decode(sh('pm2 jlist'), true) ?: [] as $p) {
        if (preg_match('/^[A-Za-z0-9._-]+$/', $p['name'])) {
            $keys[] = ['key' => "pm2:{$p['name']}", 'label' => "PM2 — {$p['name']}", 'size' => null];
        }
    }
    save('logkeys', ['keys' => $keys]);
}

function c_purge(): void
{
    global $pdo;
    $now = time();
    $pdo->exec('DELETE FROM metrics WHERE ts < ' . ($now - 31 * 86400));
    $pdo->exec('DELETE FROM site_checks WHERE ts < ' . ($now - 31 * 86400));
    $pdo->exec('DELETE FROM login_attempts WHERE ts < ' . ($now - 31 * 86400));
    $pdo->exec('DELETE FROM audit_log WHERE ts < ' . ($now - 366 * 86400));
    $day = date('Y-m-d', $now - 31 * 86400);
    foreach (['traffic_daily', 'traffic_visitors', 'traffic_top'] as $t) {
        $pdo->exec("DELETE FROM $t WHERE day < '$day'");
    }
    foreach (glob('/var/lib/hostkeep/jobs/*') ?: [] as $f) {
        if (filemtime($f) < $now - 7 * 86400) {
            @unlink($f);
        }
    }
}

$rc = 0;
foreach ($modes[$mode] as $task) {
    try {
        ('c_' . $task)();
    } catch (Throwable $e) {
        fwrite(STDERR, date('c') . " [$task] " . $e->getMessage() . "\n");
        $rc = 1;
    }
}
exit($rc);
