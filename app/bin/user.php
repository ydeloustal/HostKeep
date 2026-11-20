<?php
declare(strict_types=1);

/**
 * Accounts from the command line (root only). Usually called through « sudo hostkeep … ».
 *
 *   user.php list
 *   user.php create <identifiant> [--role=superadmin|admin|editor|viewer] [--email=E] [--name=N] [--password-stdin|--generate]
 *   user.php passwd <identifiant> [--password-stdin|--generate]     (asks interactively by default)
 *   user.php role <identifiant> <rôle>
 *   user.php enable|disable|unlock <identifiant>
 *   user.php setup [--username=U] [--email=E] [--password-stdin|--generate]
 *        first Super Admin if none exists (interactive unless options are given)
 *   user.php migrate-env      imports the single account of Hostkeep 1.x (.env) when the table is empty
 *   user.php count            number of active Super Admins (for scripts)
 *
 * --generate prints a random password once; the user must change it at first login.
 */
if (PHP_SAPI !== 'cli' || posix_geteuid() !== 0) {
    fwrite(STDERR, "À lancer avec sudo, en ligne de commande.\n");
    exit(1);
}
require __DIR__ . '/../src/bootstrap.php';
require __DIR__ . '/../src/settings.php';
require __DIR__ . '/../src/rbac.php';

$args = array_slice($argv, 1);
$opts = [];
$pos = [];
foreach ($args as $a) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $a, $m)) {
        $opts[$m[1]] = $m[2] ?? true;
    } else {
        $pos[] = $a;
    }
}
$cmd = array_shift($pos) ?? 'help';

/** str_pad counting characters, not bytes (accents). */
function pad(string $s, int $n): string
{
    return $s . str_repeat(' ', max(1, $n - mb_strlen($s)));
}

function out(string $s): void
{
    fwrite(STDOUT, $s . "\n");
}

function fail(string $s, int $rc = 1): never
{
    fwrite(STDERR, "Erreur : $s\n");
    exit($rc);
}

function tty(): mixed
{
    static $t = null;
    if ($t === null) {
        $t = posix_isatty(STDIN) ? STDIN : (@fopen('/dev/tty', 'r+') ?: false);
    }
    return $t;
}

function ask(string $prompt, bool $hidden = false, string $default = ''): string
{
    $t = tty();
    if (!$t) {
        fail('pas de terminal pour poser la question : utilise --password-stdin ou --generate.');
    }
    fwrite(STDERR, $prompt . ($default !== '' ? " [$default]" : '') . ' : ');
    if ($hidden) {
        shell_exec('stty -echo < /dev/tty 2>/dev/null');
    }
    $v = rtrim((string) fgets($t), "\r\n");
    if ($hidden) {
        shell_exec('stty echo < /dev/tty 2>/dev/null');
        fwrite(STDERR, "\n");
    }
    return $v === '' ? $default : $v;
}

function min_len(): int
{
    return max(8, setting_int('security.password_min_length'));
}

/** @return array{0:string,1:bool} password, must change at first login */
function obtain_password(array $opts, string $username): array
{
    if (!empty($opts['generate'])) {
        $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $p = '';
        for ($i = 0; $i < 16; $i++) {
            $p .= $chars[random_int(0, strlen($chars) - 1)] . ($i % 4 === 3 && $i < 15 ? '-' : '');
        }
        return [$p, true];
    }
    if (!empty($opts['password-stdin'])) {
        $p = rtrim((string) fgets(STDIN), "\r\n");
    } else {
        $p = ask('Mot de passe (' . min_len() . ' caractères minimum)', true);
        if (ask('Confirmation', true) !== $p) {
            fail('les deux saisies diffèrent.');
        }
    }
    if (mb_strlen($p) < min_len()) {
        fail('mot de passe trop court (' . min_len() . ' caractères minimum).');
    }
    if (strcasecmp($p, $username) === 0) {
        fail('le mot de passe ne doit pas être l\'identifiant.');
    }
    return [$p, false];
}

function find(string $username): array
{
    $s = db()->prepare('SELECT * FROM users WHERE username = ?');
    $s->execute([$username]);
    return $s->fetch() ?: fail("compte « $username » introuvable.");
}

function create(string $username, string $role, ?string $email, string $name, array $opts): void
{
    if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/D', $username)) {
        fail('identifiant invalide (3 à 40 caractères : lettres, chiffres, _ . -).');
    }
    if (!isset(ROLES[$role])) {
        fail('rôle inconnu : ' . implode(', ', array_keys(ROLES)));
    }
    if ($email !== null && $email !== '' && !valid_email($email)) {
        fail('adresse e-mail invalide.');
    }
    $s = db()->prepare('SELECT COUNT(*) FROM users WHERE username = ? OR (email IS NOT NULL AND email = ?)');
    $s->execute([$username, $email ?: '']);
    if ((int) $s->fetchColumn()) {
        fail('identifiant ou e-mail déjà utilisé.');
    }
    [$pass, $must] = obtain_password($opts, $username);
    $now = time();
    db()->prepare('INSERT INTO users (username, email, display_name, password_hash, role, active, must_change_password, created_at, updated_at, password_changed_at)
                   VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)')
        ->execute([$username, $email ?: null, $name ?: $username, password_hash($pass, PASSWORD_DEFAULT), $role, $must ? 1 : 0, $now, $now, $now]);
    audit("utilisateur créé en ligne de commande : $username (" . role_label($role) . ')', null, 0, 'root');
    out("Compte « $username » créé (" . role_label($role) . ').');
    if ($must) {
        out("Mot de passe temporaire : $pass");
        out('(à changer à la première connexion)');
    }
}

switch ($cmd) {
    case 'list':
        $rows = db()->query('SELECT username, email, role, active, locked_until, last_login_at FROM users ORDER BY username')->fetchAll();
        if (!$rows) {
            out('Aucun compte. Crée le premier Super Admin : sudo hostkeep admin');
            break;
        }
        out(pad('IDENTIFIANT', 20) . pad('RÔLE', 22) . pad('E-MAIL', 30) . pad('ÉTAT', 12) . 'DERNIÈRE CONNEXION');
        foreach ($rows as $r) {
            $state = !$r['active'] ? 'désactivé' : ((int) $r['locked_until'] > time() ? 'verrouillé' : 'actif');
            out(pad($r['username'], 20) . pad(role_label($r['role']), 22) . pad($r['email'] ?? '-', 30) . pad($state, 12)
                . ($r['last_login_at'] ? date('Y-m-d H:i', (int) $r['last_login_at']) : 'jamais'));
        }
        break;

    case 'create':
        create((string) ($pos[0] ?? fail('identifiant manquant.')), (string) ($opts['role'] ?? 'viewer'), isset($opts['email']) ? (string) $opts['email'] : null,
            (string) ($opts['name'] ?? ''), $opts);
        break;

    case 'setup':
        $n = (int) db()->query('SELECT COUNT(*) FROM users WHERE role = "superadmin" AND active = 1')->fetchColumn();
        if ($n > 0 && empty($opts['force'])) {
            out("Un Super Admin existe déjà ($n). Pour réinitialiser un mot de passe : sudo hostkeep passwd <identifiant>");
            break;
        }
        $interactive = empty($opts['username']) && tty();
        $u = (string) ($opts['username'] ?? ($interactive ? ask('Identifiant du Super Admin', false, 'admin') : 'admin'));
        $e = isset($opts['email']) ? (string) $opts['email'] : ($interactive ? ask('E-mail (pour « mot de passe oublié » et les alertes, facultatif)') : '');
        if ($interactive && empty($opts['generate']) && empty($opts['password-stdin'])) {
            $gen = strtolower(ask('Générer un mot de passe temporaire ? (o/N)', false, 'n'));
            if (in_array($gen, ['o', 'oui', 'y', 'yes'], true)) {
                $opts['generate'] = true;
            }
        } elseif (!$interactive && empty($opts['password-stdin'])) {
            $opts['generate'] = true;
        }
        create($u, 'superadmin', $e, (string) ($opts['name'] ?? ''), $opts);
        break;

    case 'passwd':
        $u = find((string) ($pos[0] ?? fail('identifiant manquant.')));
        [$pass, $must] = obtain_password($opts, $u['username']);
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = ?, password_changed_at = ?, updated_at = ?, failed_logins = 0, locked_until = 0 WHERE id = ?')
            ->execute([password_hash($pass, PASSWORD_DEFAULT), $must ? 1 : 0, time(), time(), $u['id']]);
        db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$u['id']]);
        audit("mot de passe de {$u['username']} changé en ligne de commande", null, 0, 'root');
        out("Mot de passe de « {$u['username']} » changé. Ses sessions ouvertes sont fermées.");
        if ($must) {
            out("Mot de passe temporaire : $pass");
        }
        break;

    case 'role':
        $u = find((string) ($pos[0] ?? fail('identifiant manquant.')));
        $role = (string) ($pos[1] ?? fail('rôle manquant : ' . implode(', ', array_keys(ROLES))));
        if (!isset(ROLES[$role])) {
            fail('rôle inconnu : ' . implode(', ', array_keys(ROLES)));
        }
        db()->prepare('UPDATE users SET role = ?, updated_at = ? WHERE id = ?')->execute([$role, time(), $u['id']]);
        db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$u['id']]);
        audit("rôle de {$u['username']} → " . role_label($role) . ' (ligne de commande)', null, 0, 'root');
        out("« {$u['username']} » est maintenant " . role_label($role) . '.');
        break;

    case 'enable':
    case 'disable':
    case 'unlock':
        $u = find((string) ($pos[0] ?? fail('identifiant manquant.')));
        if ($cmd === 'disable') {
            db()->prepare('UPDATE users SET active = 0 WHERE id = ?')->execute([$u['id']]);
            db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$u['id']]);
        } else {
            db()->prepare('UPDATE users SET active = IF(? = "enable", 1, active), failed_logins = 0, locked_until = 0 WHERE id = ?')->execute([$cmd, $u['id']]);
            db()->prepare('DELETE FROM login_attempts WHERE ok = 0')->execute();
        }
        audit("compte {$u['username']} : $cmd (ligne de commande)", null, 0, 'root');
        out("« {$u['username']} » : " . ['enable' => 'réactivé', 'disable' => 'désactivé', 'unlock' => 'débloqué'][$cmd] . '.');
        break;

    case 'migrate-env':
        if ((int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            break;
        }
        $hash = env('HOSTKEEP_PASSWORD_HASH');
        $user = env('HOSTKEEP_ADMIN_USER', 'admin');
        if ($hash === '' || !preg_match('/^[A-Za-z0-9_.@-]{3,40}$/D', $user)) {
            break;
        }
        $user = preg_replace('/[^A-Za-z0-9_.-]/', '-', $user);
        $now = time();
        db()->prepare('INSERT INTO users (username, display_name, password_hash, role, active, must_change_password, created_at, updated_at, password_changed_at)
                       VALUES (?, ?, ?, "superadmin", 1, 0, ?, ?, ?)')->execute([$user, $user, $hash, $now, $now, $now]);
        audit("compte « $user » importé de l'ancienne configuration (.env)", null, 0, 'root');
        out("Compte « $user » de Hostkeep 1.x importé comme Super Admin (même mot de passe).");
        break;

    case 'count':
        out((string) db()->query('SELECT COUNT(*) FROM users WHERE role = "superadmin" AND active = 1')->fetchColumn());
        break;

    default:
        $doc = (string) file_get_contents(__FILE__);
        preg_match('#/\*\*(.*?)\*/#s', $doc, $m);
        out(trim(preg_replace('/^\s*\* ?/m', '', $m[1] ?? '')));
        exit($cmd === 'help' ? 0 : 1);
}
