<?php
declare(strict_types=1);

/**
 * Kept for Hostkeep 1.x habits: « sudo php /var/www/hostkeep/bin/set-password.php ».
 * Creates the first Super Admin when there is none, otherwise changes the password of an account.
 * New way: sudo hostkeep admin / sudo hostkeep passwd <identifiant>.
 */
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    fwrite(STDERR, "À lancer avec sudo, en ligne de commande.\n");
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';

$tool = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/user.php');
if ((int) db()->query('SELECT COUNT(*) FROM users WHERE role = "superadmin" AND active = 1')->fetchColumn() === 0) {
    passthru("$tool setup", $rc);
    exit($rc);
}
fwrite(STDOUT, 'Identifiant du compte dont changer le mot de passe : ');
$u = trim((string) fgets(STDIN));
if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/D', $u)) {
    fwrite(STDERR, "Identifiant invalide.\n");
    exit(1);
}
passthru("$tool passwd " . escapeshellarg($u), $rc);
exit($rc);
