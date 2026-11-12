<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/live.php';
require __DIR__ . '/../src/actions.php';

security_headers();
start_session();
$poll = ($_SERVER['HTTP_X_POLL'] ?? '') === '1';
if (!is_logged(!$poll)) {
    json_out(['error' => 'auth'], 401);
}
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost && !check_csrf()) {
    json_out(['error' => 'csrf'], 403);
}
session_write_close();   // release the session lock: requests run in parallel

const SECTIONS = ['services', 'processes', 'sites', 'certs', 'pm2', 'security', 'databases', 'storage', 'cron', 'updates', 'logkeys',
    'backups', 'secscan', 'perf'];

$r = (string) ($_GET['r'] ?? '');
try {
    switch ($r) {
        case 'live':
            json_out(live());

        case 'overview':
            json_out(overview());

        case 'history':
            json_out(history((string) ($_GET['range'] ?? '24h')));

        case 'section':
            $name = (string) ($_GET['name'] ?? '');
            if (!in_array($name, SECTIONS, true)) {
                json_out(['error' => 'section inconnue'], 404);
            }
            $snap = snapshot($name);
            if ($name === 'sites' && $snap) {
                $snap['data']['uptime'] = site_uptime();
            }
            json_out($snap ?? ['updated' => 0, 'data' => null]);

        case 'log':
            json_out(read_log((string) ($_GET['key'] ?? ''), (int) ($_GET['lines'] ?? 200)));

        case 'job':
            json_out(job_status((string) ($_GET['id'] ?? '')));

        case 'traffic':
            json_out(traffic((int) ($_GET['days'] ?? 7), (string) ($_GET['host'] ?? '')));

        case 'fs':
            $res = fs_list((string) ($_GET['path'] ?? '/var/www'));
            json_out($res, isset($res['error']) ? 400 : 200);

        case 'fsread':
            $res = fs_read((string) ($_GET['path'] ?? ''));
            json_out($res, isset($res['error']) ? 400 : 200);

        case 'fsdl':
            fs_download((string) ($_GET['path'] ?? ''));

        case 'backupdl':
            backup_download((string) ($_GET['day'] ?? ''), (string) ($_GET['db'] ?? ''), (string) ($_GET['pre'] ?? ''));

        case 'audit':
            json_out(db()->query('SELECT ts, ip, user, action, job, rc FROM audit_log ORDER BY id DESC LIMIT 200')->fetchAll());

        case 'action':
            if (!$isPost) {
                json_out(['error' => 'POST requis'], 405);
            }
            $in = json_decode((string) file_get_contents('php://input'), true);
            $res = start_action((string) ($in['verb'] ?? ''), array_values((array) ($in['args'] ?? [])));
            json_out($res, isset($res['error']) ? 400 : 200);

        default:
            json_out(['error' => 'route inconnue'], 404);
    }
} catch (Throwable $e) {
    error_log('dashboard: ' . $e->getMessage());
    json_out(['error' => 'Erreur serveur : ' . $e->getMessage()], 500);
}

function history(string $range): array
{
    $ranges = ['1h' => [3600, 60], '24h' => [86400, 300], '7d' => [604800, 1800], '30d' => [2592000, 7200]];
    [$span, $bucket] = $ranges[$range] ?? $ranges['24h'];
    $since = time() - $span;
    $sql = "SELECT FLOOR(ts / $bucket) * $bucket AS t, ROUND(AVG(cpu), 1) AS cpu, ROUND(AVG(load1), 2) AS load1,
                   ROUND(AVG(mem_total - mem_avail)) AS mem_used, MAX(mem_total) AS mem_total,
                   ROUND(AVG(swap_used)) AS swap_used, ROUND(AVG(disk_used)) AS disk_used, MAX(disk_total) AS disk_total,
                   ROUND(AVG(net_rx)) AS rx, ROUND(AVG(net_tx)) AS tx
            FROM metrics WHERE ts >= $since GROUP BY t ORDER BY t";
    return ['range' => $range, 'bucket' => $bucket, 'since' => $since, 'points' => db()->query($sql)->fetchAll()];
}

function traffic(int $days, string $host): array
{
    $days = in_array($days, [1, 7, 30], true) ? $days : 7;
    $since = date('Y-m-d', time() - ($days - 1) * 86400);
    $db = db();
    $hosts = $db->prepare('SELECT d.host, SUM(d.hits) AS hits, SUM(d.bytes) AS bytes, SUM(d.s4) AS s4, SUM(d.s5) AS s5, SUM(d.bots) AS bots,
                                  (SELECT COUNT(DISTINCT v.ip_hash) FROM traffic_visitors v WHERE v.host = d.host AND v.day >= ?) AS visitors
                           FROM traffic_daily d WHERE d.day >= ? GROUP BY d.host ORDER BY hits DESC');
    $hosts->execute([$since, $since]);
    $where = $host !== '' ? 'AND host = ?' : '';
    $args = $host !== '' ? [$since, $host] : [$since];
    $s = $db->prepare("SELECT day, SUM(hits) AS hits, SUM(bots) AS bots, SUM(s4) AS s4, SUM(s5) AS s5 FROM traffic_daily WHERE day >= ? $where GROUP BY day ORDER BY day");
    $s->execute($args);
    $series = $s->fetchAll();
    $v = $db->prepare("SELECT day, COUNT(DISTINCT host, ip_hash) AS visitors FROM traffic_visitors WHERE day >= ? $where GROUP BY day");
    $v->execute($args);
    $vis = array_column($v->fetchAll(), 'visitors', 'day');
    foreach ($series as &$row) {
        $row['visitors'] = (int) ($vis[$row['day']] ?? 0);
    }
    unset($row);
    $top = [];
    $t = $db->prepare("SELECT item, SUM(hits) AS hits FROM traffic_top WHERE day >= ? AND kind = ? $where GROUP BY item ORDER BY hits DESC LIMIT 15");
    foreach (['page', '404', '5xx', 'ip', 'bot', 'ref'] as $kind) {
        $t->execute($host !== '' ? [$since, $kind, $host] : [$since, $kind]);
        $top[$kind] = $t->fetchAll();
    }
    $first = $db->query('SELECT MIN(day) FROM traffic_daily')->fetchColumn();
    return ['days' => $days, 'host' => $host, 'hosts' => $hosts->fetchAll(), 'series' => $series, 'top' => $top, 'since_first' => $first];
}

function site_uptime(): array
{
    $since = time() - 7 * 86400;
    $rows = db()->query("SELECT host, COUNT(*) AS n, SUM(code BETWEEN 200 AND 499) AS ok FROM site_checks WHERE ts >= $since GROUP BY host")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['host']] = round(100 * $r['ok'] / max(1, $r['n']), 2);
    }
    return $out;
}

function overview(): array
{
    $last = db()->query('SELECT * FROM metrics ORDER BY ts DESC LIMIT 1')->fetch() ?: null;
    $alerts = [];
    $add = static function (string $level, string $text, string $section) use (&$alerts): void {
        $alerts[] = ['level' => $level, 'text' => $text, 'section' => $section];
    };

    if (!$last || time() - (int) $last['ts'] > 180) {
        $add('warn', 'Le collecteur ne remonte plus de mesures (cron /etc/cron.d/dashboard).', 'overview');
    }
    if ($last) {
        $diskPct = 100 * $last['disk_used'] / max(1, $last['disk_total']);
        if ($diskPct > 85) {
            $add($diskPct > 95 ? 'crit' : 'warn', sprintf('Disque / rempli à %.0f %%.', $diskPct), 'storage');
        }
        $memPct = 100 * $last['mem_avail'] / max(1, $last['mem_total']);
        if ($memPct < 10) {
            $add('warn', sprintf('RAM disponible faible : %.0f %%.', $memPct), 'resources');
        }
    }
    $svc = snapshot('services')['data'] ?? [];
    foreach ($svc['units'] ?? [] as $u) {
        if (($u['ActiveState'] ?? '') !== 'active') {
            $add('crit', "Service {$u['Id']} : {$u['ActiveState']}.", 'services');
        }
    }
    foreach ($svc['failed'] ?? [] as $f) {
        $add('crit', "Unité systemd en échec : $f.", 'services');
    }
    $sites = snapshot('sites')['data'] ?? [];
    $up = $total = 0;
    foreach ($sites['sites'] ?? [] as $s) {
        if (!isset($s['code'])) {
            continue;
        }
        $total++;
        if ($s['code'] >= 200 && $s['code'] < 500) {
            $up++;
        } else {
            $add('crit', "Site {$s['name']} injoignable (HTTP {$s['code']}).", 'sites');
        }
    }
    foreach (snapshot('certs')['data']['certs'] ?? [] as $c) {
        if ($c['days'] < 15) {
            $add($c['days'] < 5 ? 'crit' : 'warn', "Certificat {$c['name']} expire dans {$c['days']} j.", 'certs');
        }
    }
    $sec = snapshot('security')['data'] ?? [];
    if (!empty($sec['reboot_required'])) {
        $add('info', 'Redémarrage requis (nouveau noyau ' . ($sec['kernel_latest'] ?? '?') . ').', 'security');
    }
    $upd = snapshot('updates')['data'] ?? [];
    if (!empty($upd['security_count'])) {
        $add('warn', "{$upd['security_count']} mise(s) à jour de sécurité en attente.", 'updates');
    }
    $scan = snapshot('secscan')['data'] ?? null;
    if ($scan) {
        $crit = count(array_filter($scan['findings'], fn($f) => $f['severity'] === 'crit'));
        if ($crit) {
            $add('crit', "Audit de sécurité : $crit problème(s) critique(s) (fichiers sensibles exposés…).", 'security');
        }
    }
    $bk = snapshot('backups')['data']['days'][0]['day'] ?? null;
    if ($bk === null || $bk < date('Y-m-d', time() - 2 * 86400)) {
        $add('warn', 'Aucune sauvegarde des bases depuis plus de 2 jours.', 'backups');
    }
    foreach (snapshot('pm2')['data']['apps'] ?? [] as $a) {
        if ($a['status'] !== 'online') {
            $add('crit', "Appli PM2 {$a['name']} : {$a['status']}.", 'cron');
        }
    }

    return [
        'host' => gethostname(),
        'metric' => $last,
        'sites_up' => $up,
        'sites_total' => $total,
        'alerts' => $alerts,
        'os' => (static function (): string {
            $o = @parse_ini_file('/etc/os-release');
            return (string) ($o['PRETTY_NAME'] ?? 'Linux');
        })(),
        'kernel' => php_uname('r'),
    ];
}
