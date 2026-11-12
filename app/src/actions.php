<?php
declare(strict_types=1);

/**
 * Allowed actions: verb => regex for each argument (anchored). The root helper re-validates
 * everything itself; this list only rejects bad input early with a clear message.
 */
const ACTIONS = [
    'service'        => ['restart|reload', '[a-z0-9@._-]{1,64}'],   // allowed list: HOSTKEEP_SERVICES, checked by the helper
    'pm2-restart'    => ['[A-Za-z0-9._-]{1,64}'],
    'site'           => ['enable|disable', '[A-Za-z0-9._-]{1,100}'],
    'certbot'        => ['dry-run|renew'],
    'apt-clean'      => [],
    'journal-vacuum' => [],
    'f2b-unban'      => ['[a-z0-9_-]{1,40}', '[0-9a-fA-F:.]{3,45}'],
    'collect-now'    => [],
    'backup-now'     => [],
    'backup-restore' => ['\d{4}-\d{2}-\d{2}', '[A-Za-z0-9_-]{1,64}'],
    'security-scan'  => [],
    'apt-update'     => [],
    'apt-upgrade'    => [],
];

const BACKUP_DIR = '/var/backups/hostkeep';

function valid_fs_path(mixed $p): bool
{
    return is_string($p) && $p !== '' && $p[0] === '/' && strlen($p) < 4096 && !str_contains($p, "\0");
}

function fs_list(string $path): array
{
    if (!valid_fs_path($path)) {
        return ['error' => 'chemin invalide'];
    }
    $r = run_helper(['fs-list', $path]);
    if ($r['rc'] !== 0) {
        return ['error' => trim($r['err']) ?: 'lecture impossible'];
    }
    return json_decode($r['out'], true) ?: ['error' => 'réponse illisible'];
}

/** Folders the file browser may open (HOSTKEEP_FILE_ROOTS, resolved by the root helper). */
function fs_roots(): array
{
    $r = run_helper(['fs-roots']);
    return $r['rc'] === 0 ? array_values(array_filter(explode("\n", trim($r['out'])), 'strlen')) : [];
}

function fs_read(string $path): array
{
    if (!valid_fs_path($path)) {
        return ['error' => 'chemin invalide'];
    }
    $max = 524288;
    $r = run_helper(['fs-read', $path, (string) ($max + 1)]);
    if ($r['rc'] !== 0) {
        return ['error' => trim($r['err']) ?: 'lecture impossible'];
    }
    $data = $r['out'];
    $truncated = strlen($data) > $max;
    $data = substr($data, 0, $max);
    $binary = str_contains($data, "\0") || !mb_check_encoding($data, 'UTF-8');
    return ['path' => $path, 'binary' => $binary, 'truncated' => $truncated, 'content' => $binary ? '' : $data];
}

/** Streams a file to the browser through the root helper (read-only roots), without loading it in memory. */
function fs_download(string $path): never
{
    if (!valid_fs_path($path)) {
        json_out(['error' => 'chemin invalide'], 400);
    }
    $p = proc_open(['/usr/bin/sudo', '-n', HELPER, 'fs-read', $path, '52428800'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $first = fread($pipes[1], 8192);
    if ($first === '' || $first === false) {
        $err = stream_get_contents($pipes[2]);
        proc_close($p);
        json_out(['error' => trim((string) $err) ?: 'fichier vide ou illisible'], 400);
    }
    send_download_headers(basename($path));
    echo $first;
    fpassthru($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    exit;
}

function backup_download(string $day, string $db, string $pre = ''): never
{
    if ($pre !== '') {
        $file = preg_match('/^\d{8}-\d{6}-[A-Za-z0-9_-]{1,64}\.sql\.gz$/D', $pre) ? BACKUP_DIR . "/pre-restore/$pre" : '';
    } else {
        $file = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $db) ? BACKUP_DIR . "/$day/$db.sql.gz" : '';
    }
    if ($file === '' || !is_file($file)) {
        json_out(['error' => 'sauvegarde introuvable'], 404);
    }
    send_download_headers(basename($pre !== '' ? $file : "$db-$day.sql.gz"), filesize($file));
    readfile($file);
    exit;
}

function send_download_headers(string $name, ?int $size = null): void
{
    $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'fichier';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $safe . '"');
    header('Cache-Control: no-store');
    if ($size !== null) {
        header('Content-Length: ' . $size);
    }
}

function valid_action(string $verb, array $args): bool
{
    if (!isset(ACTIONS[$verb]) || count($args) !== count(ACTIONS[$verb])) {
        return false;
    }
    foreach (ACTIONS[$verb] as $i => $re) {
        if (!is_string($args[$i]) || !preg_match('/^(?:' . $re . ')$/D', $args[$i])) {
            return false;
        }
    }
    return true;
}

/** Runs the root helper through sudo, without any shell. @return array{rc:int,out:string,err:string} */
function run_helper(array $args): array
{
    $p = proc_open(['/usr/bin/sudo', '-n', HELPER, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        return ['rc' => 127, 'out' => '', 'err' => 'proc_open a échoué'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['rc' => proc_close($p), 'out' => (string) $out, 'err' => (string) $err];
}

function start_action(string $verb, array $args): array
{
    if (!valid_action($verb, $args)) {
        return ['error' => 'Action refusée.'];
    }
    $r = run_helper(['job', $verb, ...$args]);
    $id = trim($r['out']);
    if ($r['rc'] !== 0 || !preg_match('/^\d+-\d+$/', $id)) {
        return ['error' => trim($r['err']) ?: 'Le lancement a échoué.'];
    }
    db()->prepare('INSERT INTO audit_log (ts, ip, user, action, job) VALUES (?, ?, ?, ?, ?)')
        ->execute([time(), client_ip(), (string) $_SESSION['user'], trim($verb . ' ' . implode(' ', $args)), $id]);
    return ['job' => $id];
}

function job_status(string $id): array
{
    if (!preg_match('/^\d+-\d+$/', $id)) {
        return ['error' => 'id invalide'];
    }
    $log = JOBS_DIR . "/$id.log";
    if (!is_file($log)) {
        return ['error' => 'tâche inconnue'];
    }
    $rcFile = JOBS_DIR . "/$id.rc";
    $rc = is_file($rcFile) ? (int) trim((string) file_get_contents($rcFile)) : null;
    if ($rc !== null) {
        db()->prepare('UPDATE audit_log SET rc = ? WHERE job = ? AND rc IS NULL')->execute([$rc, $id]);
    }
    $out = (string) file_get_contents($log, false, null, max(0, filesize($log) - 200000));
    return ['done' => $rc !== null, 'rc' => $rc, 'output' => $out];
}

function read_log(string $key, int $lines): array
{
    if (!preg_match('/^(journal-errors|fail2ban|hostkeep-auth|apache:[A-Za-z0-9._-]+|pm2:[A-Za-z0-9._-]+)$/D', $key)) {
        return ['error' => 'journal inconnu'];
    }
    $r = run_helper(['log', $key, (string) max(10, min(2000, $lines))]);
    return $r['rc'] === 0 ? ['output' => $r['out']] : ['error' => trim($r['err']) ?: 'lecture impossible'];
}
