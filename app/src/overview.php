<?php
declare(strict_types=1);

/**
 * The security snapshot is refreshed every 5 minutes: right after a reboot it still describes the
 * previous boot. When the running kernel differs from the one it recorded, correct it from what is live.
 */
function security_live(array $sec): array
{
    $running = php_uname('r');
    if (($sec['kernel_running'] ?? $running) === $running) {
        return $sec;
    }
    return array_merge($sec, ['kernel_running' => $running, 'booted_at' => null, 'reboot_stuck' => false, 'reboot_hint' => null,
        'reboot_required' => ($sec['kernel_latest'] ?? $running) !== $running, 'since_reboot' => true]);
}

/**
 * When will / be full? Straight line through the daily peak of the last 30 days (least squares).
 * null while there are fewer than 3 days of measures. days = null when the usage does not grow.
 */
function disk_forecast(): ?array
{
    $rows = db()->query('SELECT FLOOR(ts / 86400) AS d, MAX(disk_used) AS u, MAX(disk_total) AS t FROM metrics
                         WHERE ts > ' . (time() - 30 * 86400) . ' GROUP BY d ORDER BY d')->fetchAll();
    $n = count($rows);
    if ($n < 3) {
        return null;
    }
    $sx = $sy = $sxy = $sxx = 0.0;
    foreach ($rows as $r) {
        $x = (float) $r['d'];
        $y = (float) $r['u'];
        $sx += $x;
        $sy += $y;
        $sxy += $x * $y;
        $sxx += $x * $x;
    }
    $den = $n * $sxx - $sx * $sx;
    $slope = $den > 0 ? ($n * $sxy - $sx * $sy) / $den : 0.0;   // bytes per day
    $last = end($rows);
    $free = max(0, (float) $last['t'] - (float) $last['u']);
    $growing = $slope > 50 * 1024 * 1024;   // under 50 Mo/day: noise (logs, caches), not a trend
    return ['days' => $growing ? (int) floor($free / $slope) : null, 'per_day' => (int) round($slope), 'free' => (int) $free, 'samples' => $n];
}

/** Last local backup and state of the off-site copy (home page, alerts). */
function backup_summary(): array
{
    $b = snapshot('backups')['data'] ?? [];
    $last = $b['days'][0] ?? null;
    $o = snapshot('offsite')['data'] ?? [];
    return ['day' => $last['day'] ?? null, 'finished' => $last['finished'] ?? null, 'size' => $last['size'] ?? 0,
        'errors' => $last['errors'] ?? [], 'archives' => count($last['archives'] ?? []), 'dbs' => count($last['files'] ?? []),
        'offsite' => ['enabled' => !empty($o['enabled']), 'label' => $o['label'] ?? '', 'last_ok' => $o['last_ok'] ?? null,
            'last_day' => $o['last_day'] ?? null, 'last_error' => $o['last_error'] ?? null]];
}

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
    $sec = security_live(snapshot('security')['data'] ?? []);
    if (!empty($sec['reboot_stuck'])) {
        $add('warn', "Le serveur a redémarré mais tourne toujours sur l'ancien noyau {$sec['kernel_running']} (au lieu de {$sec['kernel_latest']}).", 'security');
    } elseif (!empty($sec['reboot_required'])) {
        $why = ($sec['kernel_latest'] ?? '') !== ($sec['kernel_running'] ?? '') ? 'nouveau noyau ' . ($sec['kernel_latest'] ?? '?') : 'mises à jour système';
        $add('info', "Redémarrage requis ($why).", 'security');
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
    $forecast = disk_forecast();
    if ($forecast && $forecast['days'] !== null && $forecast['days'] < 14) {
        // bucketed wording: the alert text (and so the e-mail) does not change every day
        $add($forecast['days'] < 3 ? 'crit' : 'warn', 'Le disque « / » sera plein dans moins de ' . ($forecast['days'] < 3 ? '3' : '14') . ' jours au rythme des 30 derniers jours.', 'storage');
    }
    $backup = backup_summary();
    if ($backup['day'] === null || $backup['day'] < date('Y-m-d', time() - 2 * 86400)) {
        $add('warn', 'Aucune sauvegarde depuis plus de 2 jours.', 'backups');
    } elseif ($backup['errors']) {
        $add('warn', 'Dernière sauvegarde incomplète : ' . mb_strimwidth($backup['errors'][0], 0, 160, '…'), 'backups');
    }
    $off = $backup['offsite'];
    if ($off['enabled']) {
        if ($off['last_error']) {
            $add('warn', 'Copie externe des sauvegardes en échec : ' . mb_strimwidth($off['last_error'], 0, 160, '…'), 'backups');
        } elseif ($off['last_ok'] && $off['last_ok'] < time() - 36 * 3600) {
            $add('warn', 'Aucune copie externe réussie depuis ' . intdiv(time() - $off['last_ok'], 3600) . ' h.', 'backups');
        } elseif (!$off['last_ok']) {
            $add('info', 'Copie externe activée : la première partira avec la prochaine sauvegarde (ou « Envoyer maintenant »).', 'backups');
        }
    }
    $hb = snapshot('heartbeat')['data'] ?? [];
    if (!empty($hb['enabled']) && !empty($hb['last_error'])) {
        $add('warn', 'Le ping de surveillance externe échoue (' . mb_strimwidth($hb['last_error'], 0, 120, '…') . ') : le service externe croira le serveur en panne.', 'notify');
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
        'backup' => $backup,
        'disk_forecast' => $forecast,
        'os' => (static function (): string {
            $o = @parse_ini_file('/etc/os-release');
            return (string) ($o['PRETTY_NAME'] ?? 'Linux');
        })(),
        'kernel' => php_uname('r'),
    ];
}
