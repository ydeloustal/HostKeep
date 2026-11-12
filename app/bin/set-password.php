<?php
declare(strict_types=1);

/** Sets the hostkeep login. Run it yourself on the server: sudo php /var/www/hostkeep/bin/set-password.php */

if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    fwrite(STDERR, "À lancer avec sudo, en ligne de commande.\n");
    exit(1);
}
$file = __DIR__ . '/../config.php';
$cfg = is_file($file) ? require $file : [];

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

$user = ask('Identifiant [' . ($cfg['admin_user'] ?? 'admin') . '] : ') ?: ($cfg['admin_user'] ?? 'admin');
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
$cfg['admin_user'] = $user;
$cfg['password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
$tmp = "$file.tmp";
file_put_contents($tmp, "<?php\nreturn " . var_export($cfg, true) . ";\n");
chown($tmp, 'root');
chgrp($tmp, 'hostkeep');
chmod($tmp, 0640);
rename($tmp, $file);
echo "Mot de passe enregistré pour « $user ». Tu peux te connecter.\n";
