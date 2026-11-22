<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/auth.php';
require __DIR__ . '/../src/mailer.php';
require __DIR__ . '/../src/notify.php';
require __DIR__ . '/../src/migration.php';
require __DIR__ . '/../src/users.php';
require __DIR__ . '/../src/live.php';
require __DIR__ . '/../src/actions.php';
require __DIR__ . '/../src/overview.php';

security_headers();
enforce_ip_allowlist(true);
start_session();
$poll = ($_SERVER['HTTP_X_POLL'] ?? '') === '1';
$me = current_user(!$poll);
if (!$me) {
    json_out(['error' => 'auth'], 401);
}
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($isPost && !check_csrf()) {
    json_out(['error' => 'csrf'], 403);
}
session_write_close();   // release the session lock: requests run in parallel

const SECTIONS = ['services', 'processes', 'sites', 'certs', 'pm2', 'security', 'databases', 'storage', 'cron', 'updates', 'logkeys',
    'backups', 'offsite', 'heartbeat', 'secscan', 'perf', 'panel'];

$r = (string) ($_GET['r'] ?? '');
$in = $isPost ? (json_decode((string) file_get_contents('php://input'), true) ?: []) : [];
if (!is_array($in)) {
    $in = [];
}
/* An account flagged "must change password" may only reach its own account routes until it does. */
if ($me['must_change_password'] && !in_array($r, ['me', 'account.password'], true)) {
    json_out(['error' => 'Change d\'abord ton mot de passe.', 'must_change_password' => true], 403);
}
/* Same when the role requires 2FA and the account has none yet (set up on /2fa.php). */
if ($me['must_setup_2fa'] && $r !== 'me') {
    json_out(['error' => 'Active d\'abord la double authentification.', 'must_setup_2fa' => true], 403);
}
$post = static function () use ($isPost): void {
    if (!$isPost) {
        json_out(['error' => 'POST requis'], 405);
    }
};
$uid = static fn(): int => (int) ($_GET['id'] ?? 0);

try {
    switch ($r) {
        /* ---------- session & own account ---------- */
        case 'me':
            json_out(me_payload($me));

        case 'account.profile':
            $post();
            $res = user_update($me['id'], array_intersect_key($in, array_flip(['display_name', 'email'])), $me);
            json_out($res, isset($res['errors']) ? 422 : 200);

        case 'account.password':
            $post();
            $full = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
            $full->execute([$me['id']]);
            $hash = (string) $full->fetchColumn();
            if (!password_verify((string) ($in['current'] ?? ''), $hash)) {
                json_out(['errors' => ['current' => 'Mot de passe actuel incorrect.']], 422);
            }
            $new = (string) ($in['password'] ?? '');
            if ($p = password_problem($new, $me)) {
                json_out(['errors' => ['password' => $p]], 422);
            }
            if (password_verify($new, $hash)) {
                json_out(['errors' => ['password' => 'Choisis un mot de passe différent de l\'actuel.']], 422);
            }
            set_password($me['id'], $new, false, current_session_id());
            audit('mot de passe changé');
            json_out(['ok' => true]);

        case 'account.sessions':
            json_out(sessions_of($me['id']));

        case 'account.revoke':
            $post();
            $n = revoke_sessions($me['id'], isset($in['id']) ? (string) $in['id'] : null);
            if ($n) {
                audit("$n session(s) fermée(s)");
            }
            json_out(['revoked' => $n]);

        /* ---------- users (Administration) ---------- */
        case 'users':
            require_perm($me, 'users.view');
            json_out(['users' => users_list($me), 'roles' => roles_payload($me), 'mail' => mail_configured()]);

        case 'users.create':
            $post();
            require_perm($me, 'users.manage');
            $res = user_create($in, $me);
            json_out($res, isset($res['errors']) ? 422 : 200);

        case 'users.update':
            $post();
            require_perm($me, 'users.manage');
            $res = user_update($uid(), $in, $me);
            json_out($res, isset($res['errors']) ? 422 : 200);

        case 'users.delete':
            $post();
            require_perm($me, 'users.manage');
            $err = user_delete($uid(), $me);
            json_out($err ? ['error' => $err] : ['ok' => true], $err ? 422 : 200);

        case 'users.reset':
            $post();
            require_perm($me, 'users.manage');
            $res = user_reset_password($uid(), (string) ($in['mode'] ?? 'generate'), $me);
            json_out($res, isset($res['error']) ? 422 : 200);

        case 'users.2fa_reset':
            $post();
            require_perm($me, 'users.manage');
            $t = user_by_id($uid());
            if (!$t || !can_manage_user($me, $t) || $t['id'] === $me['id']) {
                json_out(['error' => 'Tu ne peux pas réinitialiser la double authentification de ce compte.'], 403);
            }
            two_fa_disable($t['id']);
            revoke_sessions($t['id'], null);
            audit("double authentification réinitialisée : {$t['username']}");
            json_out(['ok' => true]);

        case 'users.sessions':
            require_perm($me, 'users.manage');
            $t = user_by_id($uid());
            if (!$t || !can_manage_user($me, $t)) {
                json_out(['error' => 'Compte introuvable ou hors de tes droits.'], 403);
            }
            json_out(sessions_of($t['id']));

        case 'users.revoke':
            $post();
            require_perm($me, 'users.manage');
            $t = user_by_id($uid());
            if (!$t || !can_manage_user($me, $t) || $t['id'] === $me['id']) {
                json_out(['error' => 'Compte introuvable ou hors de tes droits.'], 403);
            }
            $n = revoke_sessions($t['id'], isset($in['session']) ? (string) $in['session'] : null);
            audit("$n session(s) de {$t['username']} fermée(s)");
            json_out(['revoked' => $n]);

        /* ---------- settings ---------- */
        case 'settings':
            require_perm($me, 'settings.view');
            json_out(['groups' => settings_for($me), 'domain' => env('HOSTKEEP_DOMAIN'), 'webserver' => env('HOSTKEEP_WEBSERVER', 'apache'),
                'https' => env('HOSTKEEP_HTTPS', 'yes') !== 'no', 'version' => app_version(), 'can_domain' => can($me, 'panel.update')]);

        case 'settings.save':
            $post();
            $group = (string) ($_GET['group'] ?? '');
            require_perm($me, settings_schema()[$group]['perm'] ?? 'settings.security');
            $errors = settings_save($group, (array) ($in['values'] ?? []), $me);
            json_out($errors ? ['errors' => $errors] : ['ok' => true, 'groups' => settings_for($me)], $errors ? 422 : 200);

        case 'settings.testnotify':
            $post();
            require_perm($me, 'settings.notifications');
            $channels = array_values(array_diff(notify_channels(), ['mail']));
            if (!$channels) {
                json_out(['error' => 'Aucun canal Discord, Telegram ou webhook enregistré (le test des e-mails est dans l\'onglet E-mails).'], 422);
            }
            $msg = notify_message([['level' => 'info', 'text' => "Message de test envoyé par {$me['username']} : les alertes arriveront ici.", 'section' => 'overview']], []);
            $msg['subject'] = 'Test des alertes de ' . panel_name();
            $msg['payload']['event'] = 'test';
            $errors = notify_send($msg, $channels);
            audit('test des canaux d\'alerte : ' . implode(', ', $channels));
            json_out(['results' => array_map(fn($c) => ['channel' => CHANNEL_LABELS[$c], 'ok' => !isset($errors[$c]), 'error' => $errors[$c] ?? null], $channels)]);

        case 'panel.export':
            // classic form POST (the browser saves the answer as a file): fields in $_POST, CSRF checked above
            $post();
            require_perm($me, 'panel.update');
            $withAccounts = ($_POST['accounts'] ?? '') === '1';
            $pass = (string) ($_POST['passphrase'] ?? '');
            if ($withAccounts && (mb_strlen($pass) < 12 || $pass !== (string) ($_POST['passphrase2'] ?? ''))) {
                json_out(['error' => 'Phrase de passe : au moins 12 caractères, identique dans les deux champs.'], 422);
            }
            audit('archive de migration téléchargée' . ($withAccounts ? ' (avec les comptes, chiffrés)' : ''));
            $host = preg_replace('/[^A-Za-z0-9.-]/', '_', (string) gethostname());
            helper_stream(['export-bundle'], "hostkeep-migration-$host-" . date('Y-m-d') . '.tar.gz',
                json_encode(migration_export($withAccounts, $pass), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        case 'settings.testping':
            $post();
            require_perm($me, 'settings.notifications');
            if (setting('monitor.ping_url') === '') {
                json_out(['error' => 'Enregistre d\'abord l\'URL de ping.'], 422);
            }
            $res = heartbeat_send(true);
            audit('test du ping de surveillance externe');
            json_out($res, $res['ok'] ? 200 : 422);

        case 'settings.testmail':
            $post();
            require_perm($me, 'settings.smtp');
            $to = trim((string) ($in['to'] ?? '')) ?: (string) $me['email'];
            if (!valid_email($to)) {
                json_out(['error' => 'Indique une adresse de destination (ou renseigne ton e-mail dans Mon compte).'], 422);
            }
            try {
                send_mail($to, 'E-mail de test', "Bonjour,\n\nCet e-mail confirme que l'envoi SMTP de " . panel_name() . " fonctionne.\nEnvoyé par {$me['username']} le " . date('d/m/Y à H:i') . ".\n");
            } catch (Throwable $e) {
                json_out(['error' => $e->getMessage()], 422);
            }
            audit("e-mail de test envoyé à $to");
            json_out(['ok' => true, 'to' => $to]);

        /* ---------- monitoring ---------- */
        case 'live':
            json_out(live());

        case 'overview':
            json_out(overview());

        case 'sitehistory':
            json_out(site_history((string) ($_GET['host'] ?? ''), (string) ($_GET['days'] ?? '7')));

        case 'history':
            json_out(history((string) ($_GET['range'] ?? '24h')));

        case 'section':
            $name = (string) ($_GET['name'] ?? '');
            if (!in_array($name, SECTIONS, true)) {
                json_out(['error' => 'section inconnue'], 404);
            }
            if ($name === 'logkeys') {
                require_perm($me, 'logs.view');
            }
            $snap = snapshot($name);
            if ($name === 'sites' && $snap) {
                $snap['data']['uptime'] = site_uptime();
                $snap['data']['latency'] = site_latency_24h();
            }
            if ($name === 'security' && $snap) {
                $snap['data'] = security_live($snap['data'] ?? []);
            }
            json_out($snap ?? ['updated' => 0, 'data' => null]);

        case 'log':
            require_perm($me, 'logs.view');
            json_out(read_log((string) ($_GET['key'] ?? ''), (int) ($_GET['lines'] ?? 200)));

        case 'job':
            json_out(job_status((string) ($_GET['id'] ?? '')));

        case 'traffic':
            json_out(traffic((int) ($_GET['days'] ?? 7), (string) ($_GET['host'] ?? '')));

        case 'fs':
            require_perm($me, 'files.view');
            $res = fs_list((string) ($_GET['path'] ?? '/var/www'));
            json_out($res, isset($res['error']) ? 400 : 200);

        case 'fsroots':
            require_perm($me, 'files.view');
            json_out(['roots' => fs_roots()]);

        case 'fsread':
            require_perm($me, 'files.view');
            $res = fs_read((string) ($_GET['path'] ?? ''));
            json_out($res, isset($res['error']) ? 400 : 200);

        case 'fsdl':
            require_perm($me, 'files.view');
            audit('téléchargement de fichier ' . mb_substr((string) ($_GET['path'] ?? ''), 0, 200));
            fs_download((string) ($_GET['path'] ?? ''));

        case 'backupdl':
            require_perm($me, 'backups.download');
            if (backup_holds_panel_secrets((string) ($_GET['db'] ?? ''), isset($_GET['archive']) ? (string) $_GET['archive'] : null, (string) ($_GET['pre'] ?? ''))) {
                require_perm($me, 'backups.panel');
            }
            audit('téléchargement de sauvegarde ' . ($_GET['pre'] ?? ((isset($_GET['archive']) ? ($_GET['archive'] ?: 'complète') : ($_GET['db'] ?? '')) . ' ' . ($_GET['day'] ?? ''))));
            if (isset($_GET['archive'])) {
                backup_stream((string) ($_GET['day'] ?? ''), (string) $_GET['archive']);
            }
            backup_download((string) ($_GET['day'] ?? ''), (string) ($_GET['db'] ?? ''), (string) ($_GET['pre'] ?? ''));

        case 'audit':
            require_perm($me, 'audit.view');
            json_out(db()->query('SELECT ts, ip, user, action, job, rc FROM audit_log ORDER BY id DESC LIMIT 300')->fetchAll());

        case 'action':
            $post();
            $verb = (string) ($in['verb'] ?? '');
            $args = array_values((array) ($in['args'] ?? []));
            $perm = action_permission($verb, $args);
            if ($perm === null) {
                json_out(['error' => 'Action refusée.'], 400);
            }
            require_perm($me, $perm);
            if ($verb === 'backup-restore' && ($args[1] ?? '') === 'hostkeep') {
                require_perm($me, 'backups.panel');   // an old copy of the accounts and settings of the panel itself
            }
            $res = start_action($verb, $args);
            json_out($res, isset($res['error']) ? 400 : 200);

        default:
            json_out(['error' => 'route inconnue'], 404);
    }
} catch (Throwable $e) {
    error_log('hostkeep: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    json_out(['error' => 'Erreur serveur. Détails dans /var/log/hostkeep/php-error.log.'], 500);
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

/** Average response time of each site, hour by hour over the last 24 h (null = no successful check). */
function site_latency_24h(): array
{
    $since = time() - 86400;
    $rows = db()->query("SELECT host, FLOOR((ts - $since) / 3600) AS h, ROUND(AVG(CASE WHEN code BETWEEN 200 AND 499 THEN ms END)) AS ms
                         FROM site_checks WHERE ts >= $since GROUP BY host, h")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['host']] ??= array_fill(0, 24, null);
        $h = min(23, (int) $r['h']);
        $out[$r['host']][$h] = $r['ms'] === null ? null : (int) $r['ms'];
    }
    return $out;
}

/** Response time history of one site: average / worst time and failed checks per bucket. */
function site_history(string $host, string $days): array
{
    if (!preg_match('/^[A-Za-z0-9.-]{1,255}$/D', $host)) {
        return ['error' => 'site invalide'];
    }
    [$span, $bucket] = $days === '30' ? [30 * 86400, 6 * 3600] : [7 * 86400, 3600];
    $since = time() - $span;
    $q = db()->prepare("SELECT FLOOR(ts / $bucket) * $bucket AS t,
                               ROUND(AVG(CASE WHEN code BETWEEN 200 AND 499 THEN ms END)) AS ms,
                               MAX(CASE WHEN code BETWEEN 200 AND 499 THEN ms END) AS max_ms,
                               SUM(code NOT BETWEEN 200 AND 499) AS errors, COUNT(*) AS n
                        FROM site_checks WHERE host = ? AND ts >= ? GROUP BY t ORDER BY t");
    $q->execute([$host, $since]);
    $points = $q->fetchAll();
    $n = array_sum(array_column($points, 'n'));
    $err = array_sum(array_column($points, 'errors'));
    $ok = array_filter(array_column($points, 'ms'), static fn($v) => $v !== null);
    return ['host' => $host, 'days' => $days === '30' ? 30 : 7, 'points' => $points,
        'checks' => $n, 'errors' => $err, 'avg' => $ok ? round(array_sum($ok) / count($ok)) : null,
        'max' => $points ? max(array_map('intval', array_column($points, 'max_ms'))) : null];
}
