<?php
declare(strict_types=1);

/**
 * Collector, run as root by the systemd timers hostkeep-*.timer:
 *   minute : metrics, services, processes
 *   5min   : sites (HTTP checks), certificates, PM2, security, databases
 *   hourly : storage, cron, pending updates, list of logs, retry of the off-site copy
 *   backup : databases + files, then the off-site copy (offsite, offsite-test, offsite-fetch: by hand)
 *   purge  : drop old rows and job files
 *   all    : everything (used after install and by the "collect-now" action)
 * Only fixed commands are run here: nothing comes from the web.
 */

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    fwrite(STDERR, "À lancer en root, en ligne de commande.\n");
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/settings.php';
require __DIR__ . '/../src/mailer.php';
require __DIR__ . '/../src/notify.php';
require __DIR__ . '/../src/overview.php';
require __DIR__ . '/../src/live.php';
require __DIR__ . '/../src/webservers.php';
require __DIR__ . '/../src/collect_extra.php';
require __DIR__ . '/../src/offsite.php';

const STATE = '/var/lib/hostkeep/state.json';
putenv('PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
putenv('HOME=/root');
putenv('PM2_HOME=/root/.pm2');
putenv('LC_ALL=C');

$mode = $argv[1] ?? 'minute';
$modes = ['minute' => ['metrics', 'services', 'processes', 'schedule', 'jobs', 'heartbeat'],   // heartbeat last: it reports the others
    '5min' => ['sites', 'certs', 'pm2', 'security', 'databases', 'traffic', 'perf', 'notify'],
    'hourly' => ['storage', 'cron', 'updates', 'logkeys', 'offsite', 'backups', 'panel'],
    'purge' => ['purge'],
    'backup' => ['backup', 'offsite', 'backups'],   // nightly dumps + files, then the off-site copy
    'offsite' => ['offsite', 'backups'],            // "Envoyer maintenant"
    'offsite-test' => ['offsite_test'],             // "Tester la connexion"
    'offsite-fetch' => ['offsite_fetch'],           // hostkeep restore fetch [day]
    'scan' => ['scan'],                    // nightly security scan of the sites
    'harden-report' => ['harden_report'],  // after « Corriger » in the audit (root helper)
    'updates' => ['updates'],              // after apt update / upgrade
    'backup-list' => ['backups']];         // after a restore
$modes['all'] = array_diff(array_merge($modes['minute'], $modes['5min'], $modes['hourly']), ['notify', 'heartbeat']);
if (!isset($modes[$mode])) {
    fwrite(STDERR, "Mode inconnu : $mode\n");
    exit(1);
}

$lock = fopen("/var/lib/hostkeep/collect-$mode.lock", 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);   // previous run still going
}

$pdo = db();   // root, through the unix socket

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
    $units = [];
    foreach (managed_services() as $s) {
        $units[$s === 'postfix' ? 'postfix@-' : $s] = $s;   // postfix runs as the instance postfix@-
    }
    $units['ssh'] = null;   // shown, never restarted from the web
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
        $p['reload'] = $action !== null && $action !== 'cron';
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

function c_sites(): void
{
    global $pdo;
    $sites = web_vhosts();
    $now = time();
    $ins = $pdo->prepare('INSERT INTO site_checks (ts, host, code, ms) VALUES (?, ?, ?, ?)');
    foreach ($sites as &$s) {
        $port = array_values(array_diff($s['ports'], [80, 443]))[0] ?? null;
        $s['url'] = $s['https'] ? "https://{$s['name']}/" : 'http://' . $s['name'] . (!in_array(80, $s['ports'], true) && $port ? ":$port" : '') . '/';
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
    // configs that exist but are not enabled, per server (to re-enable them from the panel)
    $disabled = [];
    foreach (['apache' => apache_site_files(), 'nginx' => nginx_site_files()] as $server => $f) {
        foreach (array_diff($f['available'], $f['enabled']) as $conf) {
            if (!preg_match('/\.(bak|old|orig|dpkg-\w+|save|swp)$|~$/', $conf)) {
                $disabled[] = ['server' => $server, 'conf' => $conf];
            }
        }
    }
    save('sites', ['sites' => array_values($sites), 'disabled' => $disabled, 'self' => 'hostkeep',
        'servers' => array_keys(array_filter(['apache' => trim(sh('systemctl is-active apache2')) === 'active', 'nginx' => nginx_dump() !== [], 'caddy' => caddy_config() !== []])),
        'untracked' => nginx_untracked_sites(), 'traffic_logs' => traffic_logs()]);
}

function c_certs(): void
{
    $now = time();
    $certs = [];
    // Let's Encrypt (certbot), then the certificates Caddy obtains and renews by itself
    foreach ([...(glob('/etc/letsencrypt/live/*/cert.pem') ?: []), ...(glob(CADDY_CERTS . '/*/*/*.crt') ?: [])] as $f) {
        $o = sh('openssl x509 -noout -enddate -ext subjectAltName -in ' . escapeshellarg($f));
        preg_match('/notAfter=(.+)/', $o, $x);
        preg_match_all('/DNS:([^,\s]+)/', $o, $d);
        $end = isset($x[1]) ? (strtotime(trim($x[1])) ?: 0) : 0;
        $caddy = str_starts_with($f, CADDY_CERTS);
        $certs[] = ['name' => basename(dirname($f)), 'domains' => $d[1], 'expires' => $end, 'days' => (int) floor(($end - $now) / 86400),
            'by' => $caddy ? 'caddy' : 'certbot'];
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
    // Only compare kernels of the same flavour (6.1.0-54-amd64 vs 6.1.0-56-amd64, not -cloud-amd64).
    $flavour = preg_match('/^[\d.]+-\d+-(.+)$/', $running, $m) ? $m[1] : null;
    $kernels = array_values(array_filter(lines(sh('ls /boot | sed -n "s/^vmlinuz-//p" | sort -V')),
        fn($k) => $flavour === null || preg_match('/^[\d.]+-\d+-' . preg_quote($flavour, '/') . '$/', $k)));
    $latest = end($kernels) ?: $running;
    $bootedAt = time() - (int) (float) @file_get_contents('/proc/uptime');
    $installedAt = @filectime("/boot/vmlinuz-$latest") ?: null;   // ctime = install time (dpkg keeps the build mtime)
    $grubCfg = (string) @file_get_contents('/boot/grub/grub.cfg');
    $grubFirst = preg_match('/^\s*linux\s+\S*vmlinuz-(\S+)/m', $grubCfg, $m) ? $m[1] : null;
    $grubDefault = preg_match('/^\s*GRUB_DEFAULT\s*=\s*["\']?([^"\'\n]*)/m', (string) @file_get_contents('/etc/default/grub'), $m) ? trim($m[1]) : '0';
    // Rebooted after the new kernel was installed, yet still running the old one: rebooting again will not help.
    $stuck = $latest !== $running && $installedAt && $bootedAt > $installedAt;
    $hint = null;
    if ($stuck) {
        $hint = match (true) {
            $grubCfg === '' => "Le serveur a redémarré sur l'ancien noyau et n'utilise pas GRUB : le noyau est sans doute imposé par l'hébergeur (console du VPS).",
            $grubFirst !== null && $grubFirst !== $latest && in_array($grubDefault, ['0', ''], true) => "Le serveur a redémarré sur l'ancien noyau : le menu GRUB n'a pas été régénéré (premier choix : $grubFirst). Le bouton « Redémarrer le serveur » lance update-grub avant.",
            !in_array($grubDefault, ['0', ''], true) => "Le serveur a redémarré sur l'ancien noyau : GRUB_DEFAULT=$grubDefault dans /etc/default/grub impose une autre entrée du menu.",
            default => "Le serveur a redémarré sur l'ancien noyau alors que GRUB propose $latest en premier : le choix se fait sans doute côté hébergeur (console du VPS).",
        };
    }
    save('security', [
        'jails' => $jails,
        'listening' => array_values($listening),
        'logins' => array_slice(lines(sh('journalctl -u ssh --since "-7 days" --no-pager -o short-iso | grep -E "Accepted "')), -20),
        'kernel_running' => $running,
        'kernel_latest' => $latest,
        'reboot_required' => is_file('/run/reboot-required') || $latest !== $running,
        'booted_at' => $bootedAt,
        'grub_first' => $grubFirst,
        'reboot_stuck' => (bool) $stuck,
        'reboot_hint' => $hint,
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
    foreach (array_map('basename', glob('/var/spool/cron/crontabs/*') ?: []) as $u) {
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

/** Version of the panel and commits waiting on its git remote (see the "self-update" action). */
function c_panel(): void
{
    $src = trim((string) @file_get_contents('/var/lib/hostkeep/source'));
    if ($src === '' || !is_dir("$src/.git")) {
        save('panel', ['git' => false]);
        return;
    }
    $git = 'GIT_TERMINAL_PROMPT=0 git -C ' . escapeshellarg($src) . ' ';
    sh('timeout 60 ' . $git . 'fetch --quiet --prune');
    [$hash, $ts, $subject] = array_pad(explode("\t", trim(sh($git . "log -1 --format='%h%x09%ct%x09%s'")), 3), 3, '');
    // signed updates: what is waiting is the newest signed release tag, not the branch (system/hostkeep-action)
    $check = preg_split('/\s+/', trim(sh('/usr/local/sbin/hostkeep-action update-check')));
    $signed = ($check[0] ?? '') === 'signed';
    $target = $signed ? (preg_match('/^v[0-9][A-Za-z0-9._-]*$/D', $check[1] ?? '') ? $check[1] : null) : '@{u}';
    $behind = [];
    foreach ($target === null ? [] : lines(sh($git . "log --format='%h%x09%ct%x09%s' " . escapeshellarg("HEAD..$target"))) as $l) {
        [$h, $t, $s] = array_pad(explode("\t", $l, 3), 3, '');
        $behind[] = ['hash' => $h, 'ts' => (int) $t, 'subject' => $s];
    }
    $prev = trim((string) @file_get_contents('/var/lib/hostkeep/previous'));
    $previous = null;
    if (preg_match('/^[0-9a-f]{40}$/', $prev)) {
        [$ph, $pt, $ps] = array_pad(explode("\t", trim(sh($git . "log -1 --format='%h%x09%ct%x09%s' " . $prev)), 3), 3, '');
        $previous = $ph !== '' ? ['hash' => $ph, 'ts' => (int) $pt, 'subject' => $ps] : null;
    }
    save('panel', ['git' => true, 'signed' => $signed, 'target' => $signed ? $target : null, 'source' => $src, 'previous' => $previous, 'branch' => trim(sh($git . 'rev-parse --abbrev-ref HEAD')),
        'remote' => trim(sh($git . "rev-parse --abbrev-ref '@{u}'")), 'hash' => $hash, 'ts' => (int) $ts, 'subject' => $subject,
        'behind' => $behind, 'dirty' => trim(sh($git . 'status --porcelain --untracked-files=no')) !== '']);
}

function c_logkeys(): void
{
    $keys = [['key' => 'journal-errors', 'label' => 'systemd — erreurs récentes', 'size' => null],
        ['key' => 'fail2ban', 'label' => 'fail2ban', 'size' => @filesize('/var/log/fail2ban.log') ?: null],
        ['key' => 'hostkeep-auth', 'label' => 'Hostkeep — échecs de connexion', 'size' => @filesize('/var/log/hostkeep/auth.log') ?: null]];
    foreach (['apache' => 'Apache', 'nginx' => 'Nginx'] as $srv => $label) {
        foreach (glob("/var/log/$srv*/*.log") ?: [] as $f) {
            $b = basename($f);
            if (preg_match('/^[A-Za-z0-9._-]+$/', $b) && dirname($f) === ($srv === 'apache' ? '/var/log/apache2' : '/var/log/nginx')) {
                $keys[] = ['key' => "$srv:$b", 'label' => "$label — $b", 'size' => filesize($f)];
            }
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
    $pdo->exec('DELETE FROM user_sessions WHERE last_seen < ' . ($now - 86400 - 60 * max(5, setting_int('security.session_timeout'))));
    $pdo->exec('DELETE FROM password_resets WHERE expires_at < ' . $now);
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

/**
 * Writes the result of finished jobs into the actions journal. The panel does it when someone watches the job;
 * without this, a job whose window was closed kept an empty result forever. A job with no result file after
 * a day (helper killed, server rebooted) is recorded as failed (-1).
 */
function c_jobs(): void
{
    global $pdo;
    $rows = $pdo->query('SELECT DISTINCT job, ts FROM audit_log WHERE job IS NOT NULL AND rc IS NULL AND ts > ' . (time() - 8 * 86400))->fetchAll();
    $upd = $pdo->prepare('UPDATE audit_log SET rc = ? WHERE job = ? AND rc IS NULL');
    foreach ($rows as $r) {
        if (!preg_match('/^\d+-(\d+|[0-9a-f]{16})$/D', (string) $r['job'])) {
            continue;
        }
        $rcFile = "/var/lib/hostkeep/jobs/{$r['job']}.rc";
        if (is_file($rcFile)) {
            $upd->execute([(int) trim((string) file_get_contents($rcFile)), $r['job']]);
        } elseif ((int) $r['ts'] < time() - 86400) {
            $upd->execute([-1, $r['job']]);
        }
    }
}

/** Applies the backup time chosen in the settings to the systemd timer (drop-in), when it changed. */
function c_schedule(): void
{
    $t = setting('backup.time');
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $t) || !is_file('/etc/systemd/system/hostkeep-backup.timer')) {
        return;
    }
    $dir = '/etc/systemd/system/hostkeep-backup.timer.d';
    $file = "$dir/schedule.conf";
    $want = "# Written by Hostkeep from Administration › Paramètres › Sauvegardes.\n[Timer]\nOnCalendar=\nOnCalendar=*-*-* $t:00\n";
    if (@file_get_contents($file) === $want) {
        return;
    }
    @mkdir($dir, 0755, true);
    file_put_contents($file, $want);
    sh('systemctl daemon-reload && systemctl restart hostkeep-backup.timer');
}

/** Sends the alerts that appeared or cleared since the last run to every channel (Administration › Paramètres › Alertes). */
function c_notify(): void
{
    $stateFile = '/var/lib/hostkeep/notified.json';
    $channels = notify_channels();
    if (!$channels) {
        @unlink($stateFile);
        return;
    }
    $levels = setting('notify.level') === 'warn' ? ['crit', 'warn'] : ['crit'];
    $now = [];
    foreach (overview()['alerts'] as $a) {
        if (in_array($a['level'], $levels, true)) {
            $now[alert_notify_key($a)] = $a;
        }
    }
    $before = json_decode((string) @file_get_contents($stateFile), true) ?: [];
    $new = array_diff_key($now, $before);
    $gone = array_diff_key($before, $now);
    if (!$new && !$gone) {
        return;
    }
    $errors = notify_send(notify_message($new, $gone), $channels);
    if (count($errors) === count($channels)) {
        throw new RuntimeException('alertes non envoyées, nouvel essai dans 5 min : ' . implode(' | ', array_map(fn($c, $e) => CHANNEL_LABELS[$c] . " : $e", array_keys($errors), $errors)));
    }
    file_put_contents($stateFile, json_encode($now, JSON_UNESCAPED_UNICODE));   // sent on at least one channel: not again
    if ($errors) {
        throw new RuntimeException('alertes non envoyées sur ' . implode(' | ', array_map(fn($c, $e) => CHANNEL_LABELS[$c] . " : $e", array_keys($errors), $errors)));
    }
}

/** Last task of each minute: tells the external monitor that the server (and its collector) is alive. */
function c_heartbeat(): void
{
    global $rc;
    if (setting('monitor.ping_url') === '') {
        save('heartbeat', ['enabled' => false]);
        return;
    }
    $prev = snapshot_data('heartbeat');
    $res = heartbeat_send($rc === 0);
    $st = ['enabled' => true, 'kind' => setting('monitor.kind'), 'last_try' => time(), 'collector_ok' => $rc === 0,
        'last_ok' => $res['ok'] ? time() : ($prev['last_ok'] ?? null), 'last_error' => $res['error']];
    save('heartbeat', $st);
    if (!$res['ok']) {
        throw new RuntimeException('ping de surveillance externe : ' . $res['error']);
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
