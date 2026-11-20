<?php
declare(strict_types=1);

/** Server state summary and alerts: home page, header pill, and e-mail alerts (collector). */
function overview(): array
{
    $last = db()->query('SELECT * FROM metrics ORDER BY ts DESC LIMIT 1')->fetch() ?: null;
    $alerts = [];
    $add = static function (string $level, string $text, string $section) use (&$alerts): void {
        $alerts[] = ['level' => $level, 'text' => $text, 'section' => $section];
    };

    if (!$last || time() - (int) $last['ts'] > 180) {
        $add('warn', 'Le collecteur ne remonte plus de mesures (timers systemd hostkeep-*).', 'overview');
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
    $panel = snapshot('panel')['data'] ?? [];
    if (!empty($panel['behind'])) {
        $n = count($panel['behind']);
        $add('info', "Mise à jour de Hostkeep disponible ($n nouveauté" . ($n > 1 ? 's' : '') . ').', 'panel');
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
