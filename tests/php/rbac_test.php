<?php
declare(strict_types=1);

/** Roles and the panel-secrets rule of the backups (app/src/rbac.php). php tests/php/rbac_test.php */
require __DIR__ . '/../../app/src/rbac.php';

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fails += $ok ? 0 : 1;
}
$role = fn(string $r): array => ['role' => $r];

// Admin must not reach the panel's secrets; Super Admin may
foreach (['backups.panel'] as $p) {
    check("$p : refusé à l'Administrateur", !can($role('admin'), $p));
    check("$p : refusé à l'Éditeur", !can($role('editor'), $p));
    check("$p : accordé au Super Admin", can($role('superadmin'), $p));
}
check('backups.download reste accordé à l\'Administrateur', can($role('admin'), 'backups.download'));
check('files.view reste accordé à l\'Administrateur', can($role('admin'), 'files.view'));

// which downloads hold the panel's secrets
$cases = [
    ['base hostkeep', ['hostkeep', null, ''], true],
    ['autre base', ['shop', null, ''], false],
    ['base nommée hostkeep2', ['hostkeep2', null, ''], false],
    ['archive complète du jour', ['', '', ''], true],
    ['archive hostkeep', ['', 'hostkeep', ''], true],
    ['archive www', ['', 'www', ''], false],
    ['archive nginx', ['', 'nginx', ''], false],
    ['copie avant restauration de hostkeep', ['', null, '20261122-031000-hostkeep.sql.gz'], true],
    ['copie avant restauration d\'une autre base', ['', null, '20261122-031000-shop.sql.gz'], false],
    ['copie d\'une base dont le nom finit par hostkeep', ['', null, '20261122-031000-myhostkeep.sql.gz'], false],
    ['copie d\'une base nommée my-hostkeep', ['', null, '20261122-031000-my-hostkeep.sql.gz'], false],
];
foreach ($cases as [$name, $args, $want]) {
    check("secrets du panel : $name", backup_holds_panel_secrets(...$args) === $want);
}

echo $fails ? "\n$fails échec(s)\n" : "\nTous les tests passent.\n";
exit($fails ? 1 : 0);
