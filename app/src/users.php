<?php
declare(strict_types=1);

/** Account management (Administration › Utilisateurs) and the user's own account page. */

const USER_COLUMNS = 'id, username, email, display_name, role, active, must_change_password, locked_until, created_at, last_login_at, last_login_ip';

function user_by_id(int $id): ?array
{
    $s = db()->prepare('SELECT ' . USER_COLUMNS . ' FROM users WHERE id = ?');
    $s->execute([$id]);
    $u = $s->fetch();
    return $u ? normalize_user($u) : null;
}

function normalize_user(array $u): array
{
    foreach (['id', 'locked_until', 'created_at', 'last_login_at'] as $k) {
        $u[$k] = isset($u[$k]) ? (int) $u[$k] : null;
    }
    $u['active'] = (bool) $u['active'];
    $u['must_change_password'] = (bool) $u['must_change_password'];
    $u['role_label'] = role_label($u['role']);
    return $u;
}

function users_list(array $actor): array
{
    $sessions = db()->query('SELECT user_id, COUNT(*) AS n, MAX(last_seen) AS seen FROM user_sessions WHERE last_seen > ' . (time() - idle_timeout()) . ' GROUP BY user_id')
        ->fetchAll(PDO::FETCH_UNIQUE);
    $rows = db()->query('SELECT ' . USER_COLUMNS . ' FROM users ORDER BY FIELD(role, "superadmin", "admin", "editor", "viewer"), username')->fetchAll();
    return array_map(function ($u) use ($actor, $sessions) {
        $u = normalize_user($u);
        $u['sessions'] = (int) ($sessions[$u['id']]['n'] ?? 0);
        $u['last_seen'] = isset($sessions[$u['id']]) ? (int) $sessions[$u['id']]['seen'] : null;
        $u['manageable'] = $u['id'] !== $actor['id'] && can_manage_user($actor, $u);
        $u['self'] = $u['id'] === $actor['id'];
        return $u;
    }, $rows);
}

function active_superadmins(?int $except = null): int
{
    $s = db()->prepare('SELECT COUNT(*) FROM users WHERE role = "superadmin" AND active = 1 AND id <> ?');
    $s->execute([$except ?? 0]);
    return (int) $s->fetchColumn();
}

/** Checks the editable fields. @return array [clean values, errors] */
function validate_user_input(array $in, ?array $existing, array $actor): array
{
    $err = [];
    $out = [];
    if ($existing === null || array_key_exists('username', $in)) {
        $v = trim((string) ($in['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/D', $v)) {
            $err['username'] = '3 à 40 caractères : lettres, chiffres, _ . -';
        } else {
            $s = db()->prepare('SELECT id FROM users WHERE username = ? AND id <> ?');
            $s->execute([$v, $existing['id'] ?? 0]);
            if ($s->fetch()) {
                $err['username'] = 'Identifiant déjà utilisé.';
            }
            $out['username'] = $v;
        }
    }
    if (array_key_exists('email', $in)) {
        $v = trim((string) $in['email']);
        if ($v !== '' && !valid_email($v)) {
            $err['email'] = 'Adresse e-mail invalide.';
        } elseif ($v !== '') {
            $s = db()->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
            $s->execute([$v, $existing['id'] ?? 0]);
            if ($s->fetch()) {
                $err['email'] = 'Adresse déjà utilisée par un autre compte.';
            }
        }
        $out['email'] = $v === '' ? null : mb_strtolower($v);
    }
    if (array_key_exists('display_name', $in)) {
        $v = trim((string) $in['display_name']);
        if (mb_strlen($v) > 80 || preg_match('/[\x00-\x1F\x7F<>]/u', $v)) {
            $err['display_name'] = '80 caractères au plus, sans < ni >.';
        }
        $out['display_name'] = $v;
    }
    if ($existing === null || array_key_exists('role', $in)) {
        $v = (string) ($in['role'] ?? '');
        if (!in_array($v, assignable_roles($actor), true)) {
            $err['role'] = 'Rôle non autorisé pour ton compte.';
        }
        $out['role'] = $v;
    }
    if (array_key_exists('active', $in)) {
        $out['active'] = filter_var($in['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    if (array_key_exists('must_change_password', $in)) {
        $out['must_change_password'] = filter_var($in['must_change_password'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }
    return [$out, $err];
}

/**
 * Creates an account. Password: given, or generated (shown once, to change at first login),
 * or an invitation e-mail with a link to choose it.
 * @return array ['user' => ..., 'password' => generated password or null, 'invited' => bool] or ['errors' => ...]
 */
function user_create(array $in, array $actor): array
{
    [$v, $err] = validate_user_input($in + ['email' => '', 'display_name' => ''], null, $actor);
    $mode = (string) ($in['password_mode'] ?? 'generate');   // generate | set | invite
    $pass = null;
    if ($mode === 'set') {
        $pass = (string) ($in['password'] ?? '');
        if ($p = password_problem($pass, ['username' => $v['username'] ?? '', 'email' => $v['email'] ?? ''])) {
            $err['password'] = $p;
        }
    } elseif ($mode === 'invite') {
        if (empty($v['email'])) {
            $err['email'] = 'Une adresse e-mail est nécessaire pour envoyer l\'invitation.';
        } elseif (!mail_configured()) {
            $err['password_mode'] = 'Aucun serveur SMTP configuré : impossible d\'envoyer une invitation.';
        }
    } else {
        $mode = 'generate';
    }
    if ($err) {
        return ['errors' => $err];
    }
    $generated = $mode === 'generate' ? generate_password() : null;
    $hash = password_hash($pass ?? $generated ?? bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
    $now = time();
    db()->prepare('INSERT INTO users (username, email, display_name, password_hash, role, active, must_change_password, created_at, updated_at, password_changed_at)
                   VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?)')
        ->execute([$v['username'], $v['email'], $v['display_name'] ?: $v['username'], $hash, $v['role'],
            $mode === 'set' && empty($in['must_change_password']) ? 0 : 1, $now, $now, $now]);
    $id = (int) db()->lastInsertId();
    $user = user_by_id($id);
    $invited = false;
    if ($mode === 'invite') {
        db()->prepare('UPDATE users SET must_change_password = 0 WHERE id = ?')->execute([$id]);
        try {
            send_invitation($user, $actor);
            $invited = true;
        } catch (Throwable $e) {
            db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            return ['errors' => ['password_mode' => 'Envoi de l\'invitation impossible : ' . $e->getMessage()]];
        }
    }
    audit("utilisateur créé : {$user['username']} ({$user['role_label']})" . ($invited ? ', invitation envoyée' : ''));
    return ['user' => $user, 'password' => $generated, 'invited' => $invited];
}

function send_invitation(array $user, array $actor): void
{
    $link = base_url() . '/password.php?token=' . create_reset_token($user['id'], 72 * 3600) . '&invite=1';
    send_mail($user['email'], 'Ton accès à ' . panel_name(),
        "Bonjour {$user['display_name']},\n\n" . ($actor['display_name'] ?: $actor['username']) . ' t\'a créé un compte sur ' . panel_name() . ".\n\n"
        . "Identifiant : {$user['username']}\nRôle : {$user['role_label']}\n\nChoisis ton mot de passe avec ce lien (valable 72 heures) :\n$link\n");
}

/** @return array ['user' => ...] or ['errors' => ...] */
function user_update(int $id, array $in, array $actor): array
{
    $target = user_by_id($id);
    if (!$target) {
        return ['errors' => ['_' => 'Compte introuvable.']];
    }
    $self = $target['id'] === $actor['id'];
    if (!$self && !can_manage_user($actor, $target)) {
        return ['errors' => ['_' => 'Tu ne peux pas modifier ce compte (rôle égal ou supérieur au tien).']];
    }
    if ($self) {
        unset($in['role'], $in['active'], $in['must_change_password']);   // no self-promotion, no self-lockout
    }
    [$v, $err] = validate_user_input($in, $target, $actor);
    if ($err) {
        return ['errors' => $err];
    }
    $demoted = isset($v['role']) && $target['role'] === 'superadmin' && $v['role'] !== 'superadmin';
    $disabled = isset($v['active']) && !$v['active'] && $target['active'];
    if (($demoted || $disabled) && $target['role'] === 'superadmin' && active_superadmins($target['id']) === 0) {
        return ['errors' => ['_' => 'C\'est le dernier Super Admin actif : il doit le rester.']];
    }
    if (!$v) {
        return ['user' => $target];
    }
    $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($v)));
    db()->prepare("UPDATE users SET $sets, updated_at = ? WHERE id = ?")->execute([...array_values($v), time(), $id]);
    if ($disabled || (isset($v['role']) && $v['role'] !== $target['role'])) {
        db()->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$id]);   // new rights apply at next login
    }
    if (isset($v['active']) && $v['active']) {
        db()->prepare('UPDATE users SET locked_until = 0, failed_logins = 0 WHERE id = ?')->execute([$id]);
    }
    $changes = [];
    foreach ($v as $k => $val) {
        if ((string) ($target[$k] ?? '') !== (string) $val && !($k === 'active' && (bool) $val === $target['active'])) {
            $changes[] = $k === 'role' ? 'rôle → ' . role_label($val) : ($k === 'active' ? ($val ? 'réactivé' : 'désactivé') : $k);
        }
    }
    if ($changes) {
        audit("utilisateur modifié : {$target['username']} (" . implode(', ', $changes) . ')');
    }
    return ['user' => user_by_id($id)];
}

function user_delete(int $id, array $actor): ?string
{
    $target = user_by_id($id);
    if (!$target) {
        return 'Compte introuvable.';
    }
    if ($target['id'] === $actor['id']) {
        return 'Tu ne peux pas supprimer ton propre compte.';
    }
    if (!can_manage_user($actor, $target)) {
        return 'Tu ne peux pas supprimer ce compte (rôle égal ou supérieur au tien).';
    }
    if ($target['role'] === 'superadmin' && $target['active'] && active_superadmins($target['id']) === 0) {
        return 'C\'est le dernier Super Admin actif.';
    }
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    audit("utilisateur supprimé : {$target['username']} ({$target['role_label']})");
    return null;
}

/** Admin reset: new temporary password (returned once) or a reset e-mail. */
function user_reset_password(int $id, string $mode, array $actor): array
{
    $target = user_by_id($id);
    if (!$target || !can_manage_user($actor, $target) || $target['id'] === $actor['id']) {
        return ['error' => 'Tu ne peux pas réinitialiser ce compte.'];
    }
    if ($mode === 'email') {
        if (!$target['email'] || !mail_configured()) {
            return ['error' => 'Pas d\'adresse e-mail sur ce compte ou SMTP non configuré.'];
        }
        $link = base_url() . '/password.php?token=' . create_reset_token($id, 24 * 3600);
        try {
            send_mail($target['email'], 'Réinitialisation de ton mot de passe',
                "Bonjour {$target['display_name']},\n\nUn administrateur a demandé la réinitialisation du mot de passe de ton compte « {$target['username']} ».\n\nChoisis un nouveau mot de passe avec ce lien (valable 24 heures) :\n$link\n");
        } catch (Throwable $e) {
            return ['error' => 'Envoi impossible : ' . $e->getMessage()];
        }
        audit("lien de réinitialisation envoyé à {$target['username']}");
        return ['sent' => true];
    }
    $pass = generate_password();
    set_password($id, $pass, true);
    audit("mot de passe réinitialisé : {$target['username']}");
    return ['password' => $pass];
}

function sessions_of(int $userId): array
{
    $s = db()->prepare('SELECT id, created_at, last_seen, ip, user_agent FROM user_sessions WHERE user_id = ? AND last_seen > ? ORDER BY last_seen DESC');
    $s->execute([$userId, time() - idle_timeout()]);
    $cur = current_session_id();
    return array_map(fn($r) => ['id' => substr($r['id'], 0, 16), 'created_at' => (int) $r['created_at'], 'last_seen' => (int) $r['last_seen'],
        'ip' => $r['ip'], 'agent' => describe_agent($r['user_agent']), 'current' => hash_equals($r['id'], $cur)], $s->fetchAll());
}

/** Ends sessions of a user: one (id prefix from sessions_of), or all but the current one. */
function revoke_sessions(int $userId, ?string $prefix): int
{
    if ($prefix !== null) {
        if (!preg_match('/^[0-9a-f]{16}$/D', $prefix)) {
            return 0;
        }
        $s = db()->prepare('DELETE FROM user_sessions WHERE user_id = ? AND id LIKE ? AND id <> ?');
        $s->execute([$userId, $prefix . '%', current_session_id()]);
    } else {
        $s = db()->prepare('DELETE FROM user_sessions WHERE user_id = ? AND id <> ?');
        $s->execute([$userId, current_session_id()]);
    }
    return $s->rowCount();
}

/** "Firefox · Linux" from a user agent string. */
function describe_agent(string $ua): string
{
    $b = match (true) {
        str_contains($ua, 'Edg/') => 'Edge', str_contains($ua, 'OPR/') => 'Opera', str_contains($ua, 'Firefox/') => 'Firefox',
        str_contains($ua, 'Chrome/') => 'Chrome', str_contains($ua, 'Safari/') => 'Safari', $ua === '' => 'Inconnu', default => 'Navigateur',
    };
    $o = match (true) {
        str_contains($ua, 'Android') => 'Android', (bool) preg_match('/iPhone|iPad/', $ua) => 'iOS', str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'macOS', str_contains($ua, 'Linux') => 'Linux', default => '',
    };
    return $o ? "$b · $o" : $b;
}

/** What the browser needs to know about the signed-in user (also embedded in index.php). */
function me_payload(array $me): array
{
    return ['id' => $me['id'], 'username' => $me['username'], 'display_name' => $me['display_name'] ?: $me['username'], 'email' => $me['email'],
        'role' => $me['role'], 'role_label' => role_label($me['role']), 'perms' => role_permissions($me['role']),
        'must_change_password' => $me['must_change_password'], 'panel_name' => panel_name(), 'password_min' => password_min_length(),
        'mail' => mail_configured(), 'version' => app_version(), 'last_login_at' => $me['last_login_at'] ? (int) $me['last_login_at'] : null];
}

function roles_payload(array $me): array
{
    return ['list' => array_map(fn($k, $r) => ['id' => $k, 'label' => $r['label'], 'desc' => $r['desc'], 'perms' => role_permissions($k)], array_keys(ROLES), ROLES),
        'permissions' => array_map(fn($k, $p) => ['id' => $k, 'label' => $p[1], 'min' => $p[0]], array_keys(PERMISSIONS), PERMISSIONS),
        'assignable' => assignable_roles($me)];
}
