<?php
declare(strict_types=1);

/** Sets the hostkeep login. Run it yourself on the server: sudo php /var/www/hostkeep/bin/set-password.php */

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    fwrite(STDERR, "À lancer avec sudo, en ligne de commande.\n");
    exit(1);
}
require __DIR__ . '/../src/env.php';
$file = __DIR__ . '/../.env';
if (!is_file($file)) {
    fwrite(STDERR, "Fichier $file introuvable : relance l'installation (install.sh app).\n");
    exit(1);
}
$current = env('HOSTKEEP_ADMIN_USER', 'admin');

function ask(string $prompt, bool $hidden = false): string
{
    fwrite(STDOUT, $prompt);
    if ($hidden) {
        shell_exec('stty -echo');
    }
    $v = rtrim((string) fgets(STDIN), "\r\n");
    if ($hidden) {
        shell_exec('stty echo');
        fwrite(STDOUT, "\n");
    }
    return $v;
}

$user = ask("Identifiant [$current] : ") ?: $current;
if (!preg_match('/^[A-Za-z0-9_.@-]{3,40}$/', $user)) {
    fwrite(STDERR, "Identifiant invalide (3 à 40 caractères : lettres, chiffres, _ . @ -).\n");
    exit(1);
}
$p1 = ask('Nouveau mot de passe (12 caractères minimum) : ', true);
if (strlen($p1) < 12) {
    fwrite(STDERR, "Trop court.\n");
    exit(1);
}
if (ask('Confirmation : ', true) !== $p1) {
    fwrite(STDERR, "Les deux saisies diffèrent.\n");
    exit(1);
}
/** Replaces KEY=... in the .env lines (or appends it), value single-quoted: a bcrypt hash contains "$". */
function set_line(array $lines, string $key, string $value): array
{
    $out = [];
    $done = false;
    foreach ($lines as $l) {
        if (preg_match('/^\s*' . $key . '\s*=/', $l)) {
            $l = "$key='$value'";
            $done = true;
        }
        $out[] = $l;
    }
    if (!$done) {
        $out[] = "$key='$value'";
    }
    return $out;
}
$lines = file($file, FILE_IGNORE_NEW_LINES);
$lines = set_line($lines, 'HOSTKEEP_ADMIN_USER', $user);
$lines = set_line($lines, 'HOSTKEEP_PASSWORD_HASH', password_hash($p1, PASSWORD_DEFAULT));
$tmp = "$file.tmp";
file_put_contents($tmp, implode("\n", $lines) . "\n");
chown($tmp, 'root');
chgrp($tmp, 'hostkeep');
chmod($tmp, 0640);
rename($tmp, $file);
echo "Mot de passe enregistré pour « $user ». Tu peux te connecter.\n";
