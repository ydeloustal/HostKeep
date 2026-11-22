<?php
declare(strict_types=1);

/** Notification identity of the alerts (app/src/overview.php). php tests/php/alerts_test.php */
require __DIR__ . '/../../app/src/overview.php';

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fails += $ok ? 0 : 1;
}
$a = fn(string $level, string $text, ?string $key = null): array => ['level' => $level, 'text' => $text, 'section' => 'x'] + ($key !== null ? ['key' => $key] : []);

check('RAM 7 % puis 6 % : même alerte', alert_notify_key($a('warn', 'RAM disponible faible : 7 %.', 'ram:low')) === alert_notify_key($a('warn', 'RAM disponible faible : 6 %.', 'ram:low')));
check('site en 502 puis 000 : même alerte', alert_notify_key($a('crit', 'Site a.fr injoignable (HTTP 502).', 'site:a.fr')) === alert_notify_key($a('crit', 'Site a.fr injoignable (HTTP 0).', 'site:a.fr')));
check('deux sites différents : deux alertes', alert_notify_key($a('crit', 'Site a.fr injoignable (HTTP 502).', 'site:a.fr')) !== alert_notify_key($a('crit', 'Site b.fr injoignable (HTTP 502).', 'site:b.fr')));
check('avertissement puis critique : nouvelle alerte', alert_notify_key($a('warn', 'Disque / rempli à 90 %.', 'disk:/')) !== alert_notify_key($a('crit', 'Disque / rempli à 96 %.', 'disk:/')));
check('sans clé : identité = texte (inchangée depuis la 2.9)', alert_notify_key($a('crit', 'Unité systemd en échec : x.service.')) === md5('crit' . 'Unité systemd en échec : x.service.'));

echo $fails ? "\n$fails échec(s)\n" : "\nTous les tests passent.\n";
exit($fails ? 1 : 0);
