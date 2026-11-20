<?php
declare(strict_types=1);

/**
 * Off-site copy of the backups with rclone (run as root by bin/collect.php).
 * The settings (Administration › Sauvegarde externe) are rendered into /etc/hostkeep/rclone.conf (0600) at
 * each run: remote "hostkeep", wrapped by "hostkeep-crypt" when a passphrase is set. Each local day is copied to
 * <destination>/<server>/<day>; a day is sent again when its manifest changes (a second backup the same day).
 */

const RCLONE_CONF = '/etc/hostkeep/rclone.conf';
const OFFSITE_STATE = '/var/lib/hostkeep/offsite.json';

function rclone_bin(): ?string
{
    $b = trim(sh('command -v rclone'));
    return $b !== '' ? $b : null;
}

/** rclone's reversible "obscure" format for passwords in rclone.conf (read from stdin: never on a command line). */
function rclone_obscure(string $plain): string
{
    $p = proc_open([rclone_bin(), 'obscure', '-'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException('rclone obscure impossible');
    }
    fwrite($pipes[0], $plain);
    fclose($pipes[0]);
    $out = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($p) !== 0 || $out === '') {
        throw new RuntimeException('rclone obscure a échoué');
    }
    return $out;
}

function offsite_host(): string
{
    return preg_replace('/[^A-Za-z0-9._-]/', '_', (string) gethostname()) ?: 'serveur';
}

function offsite_crypted(): bool
{
    return setting('offsite.crypt_password') !== '';
}

/** Where the days go, as an rclone path. */
function offsite_dest(): string
{
    $path = trim(setting('offsite.path'), '/');
    return offsite_crypted() ? 'hostkeep-crypt:' . offsite_host() : 'hostkeep:' . ($path !== '' ? "$path/" : '') . offsite_host();
}

/** Human description of the destination, without any secret. */
function offsite_label(): string
{
    $type = setting('offsite.type');
    $where = match ($type) {
        's3' => 'S3 ' . (setting('offsite.s3_endpoint') ?: 'Amazon'),
        'sftp' => 'SFTP ' . setting('offsite.sftp_user') . '@' . setting('offsite.sftp_host'),
        default => 'rclone (' . (preg_match('/^\s*type\s*=\s*(\S+)/m', setting('offsite.rclone_config'), $m) ? $m[1] : '?') . ')',
    };
    return $where . ' : ' . (trim(setting('offsite.path'), '/') ?: '/') . (offsite_crypted() ? ' (chiffré)' : '');
}

/** Changes when the destination changes: the days are then sent again. */
function offsite_signature(): string
{
    return md5(implode('|', [setting('offsite.type'), setting('offsite.s3_endpoint'), setting('offsite.sftp_host'), setting('offsite.path'),
        md5(setting('offsite.rclone_config')), md5(setting('offsite.crypt_password')), offsite_host()]));
}

/** Writes /etc/hostkeep/rclone.conf from the settings. @return string|null the problem, null when written */
function offsite_write_conf(): ?string
{
    if (!rclone_bin()) {
        return 'rclone n\'est pas installé : sudo apt install rclone (ou sudo hostkeep update).';
    }
    if (trim(setting('offsite.path'), '/') === '' && setting('offsite.type') !== 'rclone') {
        return 'Indique le dossier distant (Administration › Paramètres › Sauvegarde externe).';
    }
    $c = ["# Écrit par Hostkeep depuis Administration › Paramètres › Sauvegarde externe : ne pas modifier à la main.", '[hostkeep]'];
    switch (setting('offsite.type')) {
        case 's3':
            if (setting('offsite.s3_access_key') === '' || setting('offsite.s3_secret_key') === '') {
                return 'Renseigne la clé d\'accès et la clé secrète S3.';
            }
            array_push($c, 'type = s3', 'provider = ' . setting('offsite.s3_provider'), 'env_auth = false',
                'access_key_id = ' . setting('offsite.s3_access_key'), 'secret_access_key = ' . setting('offsite.s3_secret_key'));
            if (setting('offsite.s3_endpoint') !== '') {
                $c[] = 'endpoint = ' . setting('offsite.s3_endpoint');
            }
            if (setting('offsite.s3_region') !== '') {
                $c[] = 'region = ' . setting('offsite.s3_region');
            }
            break;
        case 'sftp':
            if (setting('offsite.sftp_host') === '' || setting('offsite.sftp_user') === '') {
                return 'Renseigne le serveur et l\'utilisateur SFTP.';
            }
            if (setting('offsite.sftp_password') === '' && setting('offsite.sftp_key') === '') {
                return 'Renseigne un mot de passe ou une clé privée SFTP.';
            }
            array_push($c, 'type = sftp', 'host = ' . setting('offsite.sftp_host'), 'port = ' . setting_int('offsite.sftp_port'),
                'user = ' . setting('offsite.sftp_user'));
            if (setting('offsite.sftp_key') !== '') {
                $c[] = 'key_pem = ' . str_replace("\n", '\n', trim(setting('offsite.sftp_key')));
            }
            if (setting('offsite.sftp_password') !== '') {
                $c[] = 'pass = ' . rclone_obscure(setting('offsite.sftp_password'));
            }
            break;
        default:
            $raw = setting('offsite.rclone_config');
            if ($raw === '') {
                return 'Colle la configuration rclone de la destination.';
            }
            foreach (explode("\n", $raw) as $l) {
                $l = trim($l);
                if ($l !== '' && $l[0] !== '#' && $l[0] !== ';' && $l[0] !== '[') {
                    $c[] = $l;
                }
            }
    }
    if (offsite_crypted()) {
        $path = trim(setting('offsite.path'), '/');
        array_push($c, '', '[hostkeep-crypt]', 'type = crypt', 'remote = hostkeep:' . $path, 'filename_encryption = standard',
            'directory_name_encryption = true', 'password = ' . rclone_obscure(setting('offsite.crypt_password')));
    }
    if (!is_dir(dirname(RCLONE_CONF))) {
        mkdir(dirname(RCLONE_CONF), 0750, true);
    }
    $tmp = RCLONE_CONF . '.tmp';
    @unlink($tmp);
    $old = umask(077);
    file_put_contents($tmp, implode("\n", $c) . "\n");
    umask($old);
    chmod($tmp, 0600);
    rename($tmp, RCLONE_CONF);
    return null;
}

/** Runs rclone with the panel's config. @return array{0:int,1:string} exit code, last lines of output */
function rclone(array $args, int $timeout = 300, ?string $stdin = null): array
{
    $cmd = ['timeout', (string) $timeout, rclone_bin(), '--config', RCLONE_CONF, '--retries', '3', '--low-level-retries', '10',
        '--contimeout', '30s', '--timeout', '5m'];
    if (setting('offsite.bwlimit') !== '') {
        array_push($cmd, '--bwlimit', setting('offsite.bwlimit'));
    }
    $line = implode(' ', array_map('escapeshellarg', array_merge($cmd, $args)));
    if ($stdin !== null) {
        $line = 'printf %s ' . escapeshellarg($stdin) . ' | ' . $line;
    }
    $out = [];
    exec("bash -c " . escapeshellarg("set -o pipefail; $line") . ' 2>&1', $out, $rc);
    if ($rc === 124) {
        $out[] = "délai dépassé ({$timeout} s)";
    }
    // never show a secret that rclone might echo back
    // without rclone's timestamps (the alert text stays the same from one failed run to the next: one e-mail, not one per hour)
    $clean = fn($l) => preg_replace(['#^\d{4}/\d\d/\d\d \d\d:\d\d:\d\d #', '/(secret|pass|key)[^ ]*\s*[=:]\s*\S+/i'], ['', '$1=***'], $l);
    $tail = implode("\n", array_slice(array_map($clean, $out), -6));
    return [$rc, $tail];
}

function local_backup_days(): array
{
    $days = array_map('basename', glob(BACKUP_DIR . '/????-??-??', GLOB_ONLYDIR) ?: []);
    sort($days);
    return $days;
}

/** After each backup and every hour: sends the days not yet copied, then prunes the remote. */
function c_offsite(): void
{
    $prev = snapshot_data('offsite');
    $st = ['enabled' => setting_bool('offsite.enabled'), 'label' => offsite_label(), 'keep' => setting_int('offsite.keep_days'),
        'crypt' => offsite_crypted(), 'last_ok' => $prev['last_ok'] ?? null, 'last_day' => $prev['last_day'] ?? null,
        'last_try' => $prev['last_try'] ?? null, 'last_error' => $prev['last_error'] ?? null, 'bytes' => $prev['bytes'] ?? null];
    if (!$st['enabled']) {
        $st['last_error'] = null;
        save('offsite', $st);
        return;
    }
    $lock = fopen('/var/lib/hostkeep/offsite.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return;   // a copy is already running (nightly backup + hourly retry)
    }
    $sent = json_decode((string) @file_get_contents(OFFSITE_STATE), true) ?: [];
    if (($sent['_sig'] ?? '') !== offsite_signature()) {
        $sent = ['_sig' => offsite_signature()];   // new destination: everything goes again
    }
    $todo = [];
    foreach (local_backup_days() as $day) {
        $m = BACKUP_DIR . "/$day/manifest.json";
        $stamp = is_file($m) ? filemtime($m) : filemtime(BACKUP_DIR . "/$day");
        if (($sent[$day] ?? 0) < $stamp) {
            $todo[$day] = $stamp;
        }
    }
    $error = null;
    if ($todo) {
        $st['last_try'] = time();
        $error = offsite_write_conf();
        $dest = offsite_dest();
        foreach ($error === null ? $todo : [] as $day => $stamp) {
            echo "# rclone copy $day -> " . offsite_label() . "\n";
            [$rc, $out] = rclone(['copy', BACKUP_DIR . "/$day", "$dest/$day"], 6 * 3600);
            if ($rc !== 0) {
                $error = "copie du $day : $out";
                break;
            }
            $sent[$day] = $stamp;
            $st['last_ok'] = time();
            $st['last_day'] = $day;
            $st['bytes'] = (int) sh('du -sb ' . escapeshellarg(BACKUP_DIR . "/$day") . ' | cut -f1');
        }
        if ($error === null) {
            echo "# rétention distante : suppression des copies de plus de {$st['keep']} jours\n";
            [$rc, $out] = rclone(['delete', $dest, '--min-age', "{$st['keep']}d"], 1800);
            if ($rc === 0) {
                rclone(['rmdirs', $dest, '--leave-root'], 1800);
            } else {
                echo "rétention distante en échec (sans gravité) : $out\n";
            }
        }
        $local = array_flip(local_backup_days());
        $sent = array_filter($sent, fn($v, $k) => $k === '_sig' || isset($local[$k]), ARRAY_FILTER_USE_BOTH);
        file_put_contents(OFFSITE_STATE, json_encode($sent));
        $st['last_error'] = $error;
    }
    save('offsite', $st);
    if ($error !== null) {
        throw new RuntimeException("sauvegarde externe : $error");
    }
    echo $todo ? "Copie externe terminée.\n" : "Copie externe déjà à jour.\n";
}

/** "Tester la connexion": config, folder, write + read + delete of a small file. */
function c_offsite_test(): void
{
    if ($e = offsite_write_conf()) {
        throw new RuntimeException($e);
    }
    $dest = offsite_dest();
    echo trim(sh('rclone version | head -n1')) . "\nDestination : " . offsite_label() . "\n";
    $steps = [
        ['Création du dossier', ['mkdir', $dest], null],
        ['Écriture d\'un fichier test', ['rcat', "$dest/.hostkeep-test"], 'hostkeep ' . date('c')],
        ['Lecture du fichier test', ['cat', "$dest/.hostkeep-test"], null],
        ['Suppression du fichier test', ['deletefile', "$dest/.hostkeep-test"], null],
    ];
    foreach ($steps as [$label, $args, $in]) {
        [$rc, $out] = rclone($args, 120, $in);
        echo ($rc === 0 ? '  ✔ ' : '  ✗ ') . $label . "\n";
        if ($rc !== 0) {
            throw new RuntimeException($out ?: "échec ($rc)");
        }
    }
    [, $list] = rclone(['lsf', $dest, '--dirs-only'], 120);
    echo "Jours déjà présents : " . (trim($list) !== '' ? str_replace("\n", ' ', trim($list)) : 'aucun') . "\n";
    echo "Connexion OK.\n";
}

/** hostkeep restore fetch [day]: lists the remote days, or brings one back into /var/backups/hostkeep. */
function c_offsite_fetch(): void
{
    global $argv;
    if ($e = offsite_write_conf()) {
        throw new RuntimeException($e);
    }
    $dest = offsite_dest();
    $day = $argv[2] ?? '';
    if ($day === '') {
        [$rc, $out] = rclone(['lsf', $dest, '--dirs-only'], 300);
        if ($rc !== 0) {
            throw new RuntimeException($out);
        }
        echo "Sauvegardes disponibles sur " . offsite_label() . " :\n" . preg_replace('#/$#m', '', $out) . "\n";
        return;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day)) {
        throw new RuntimeException('jour au format AAAA-MM-JJ');
    }
    $dir = BACKUP_DIR . "/$day";
    echo "# rclone copy $dest/$day -> $dir\n";
    [$rc, $out] = rclone(['copy', "$dest/$day", $dir], 12 * 3600);
    if ($rc !== 0) {
        throw new RuntimeException($out);
    }
    chgrp($dir, 'hostkeep');
    chmod($dir, 0750);
    foreach (glob("$dir/*") ?: [] as $f) {
        if (is_file($f)) {
            chgrp($f, 'hostkeep');
            chmod($f, 0640);
        }
    }
    if (is_dir("$dir/files")) {
        chmod("$dir/files", 0700);
        foreach (glob("$dir/files/*") ?: [] as $f) {
            chmod($f, 0600);   // private keys and site secrets: root only, as when they were made
        }
    }
    $m = json_decode((string) @file_get_contents("$dir/manifest.json"), true) ?: [];
    foreach ($m['items'] ?? [] as $it) {
        $f = "$dir/{$it['file']}";
        if (is_file($f) && !hash_equals($it['sha256'], hash_file('sha256', $f))) {
            throw new RuntimeException("{$it['file']} : somme de contrôle différente, fichier abîmé");
        }
    }
    echo "Rapatrié et vérifié dans $dir. Restaure ensuite une base depuis le panel (Données › Sauvegardes) ou des fichiers avec « hostkeep restore files $day <archive> ».\n";
}
