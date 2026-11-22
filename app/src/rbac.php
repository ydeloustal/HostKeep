<?php
declare(strict_types=1);

/**
 * Roles and permissions. Roles are fixed (no custom roles): each one includes everything the roles
 * below it can do. The server checks every request against this table; the browser only uses it
 * to hide what the user cannot do.
 */
const ROLES = [
    'superadmin' => ['rank' => 4, 'label' => 'Super Admin', 'desc' => 'Tous les droits : comptes administrateurs, sécurité du panel, mises à jour du panel.'],
    'admin' => ['rank' => 3, 'label' => 'Administrateur', 'desc' => 'Administre le serveur et le panel : actions système, sauvegardes, fichiers, comptes Éditeur et Lecteur, paramètres (hors sécurité).'],
    'editor' => ['rank' => 2, 'label' => 'Éditeur / Modérateur', 'desc' => 'Exploite au quotidien : contrôles, redémarrage des services, débannissement d\'IP, journaux.'],
    'viewer' => ['rank' => 1, 'label' => 'Lecteur', 'desc' => 'Consulte les tableaux de bord, sans aucune action ni accès aux journaux et fichiers.'],
];

/** permission => [minimum role, label shown in the roles matrix] */
const PERMISSIONS = [
    'dashboard.view' => ['viewer', 'Consulter les tableaux de bord (état, sites, système, sécurité, bases)'],
    'actions.routine' => ['editor', 'Lancer les contrôles : sites, audit, mises à jour disponibles, test des certificats, sauvegarde'],
    'actions.services' => ['editor', 'Redémarrer / recharger un service ou une appli PM2, débannir une IP'],
    'logs.view' => ['editor', 'Lire les journaux'],
    'actions.system' => ['admin', 'Actions système : vhosts, renouvellement des certificats, installation des mises à jour, redémarrage du serveur, nettoyage, restauration de base'],
    'backups.download' => ['admin', 'Télécharger les sauvegardes'],
    'files.view' => ['admin', 'Explorateur de fichiers (lecture seule)'],
    'audit.view' => ['admin', 'Journal des actions'],
    'users.view' => ['admin', 'Voir les comptes'],
    'users.manage' => ['admin', 'Créer et modifier les comptes de rang inférieur'],
    'settings.view' => ['admin', 'Voir les paramètres'],
    'settings.general' => ['admin', 'Paramètres généraux'],
    'settings.smtp' => ['admin', 'Paramètres e-mail (SMTP)'],
    'settings.notifications' => ['admin', 'Alertes par e-mail'],
    'settings.backups' => ['admin', 'Paramètres des sauvegardes'],
    'backups.panel' => ['superadmin', 'Télécharger ou restaurer ce qui contient les secrets du panel (base hostkeep, archive hostkeep, archive complète d\'un jour)'],
    'settings.security' => ['superadmin', 'Paramètres de sécurité (sessions, mots de passe, IP autorisées)'],
    'panel.update' => ['superadmin', 'Mettre à jour le panel, revenir en arrière, changer de domaine'],
];

/** Root actions (app/src/actions.php) => permission needed to start them. */
const ACTION_PERMS = [
    'collect-now' => 'actions.routine', 'security-scan' => 'actions.routine', 'apt-update' => 'actions.routine',
    'backup-now' => 'actions.routine', 'offsite-now' => 'actions.routine', 'certbot:dry-run' => 'actions.routine',
    'service' => 'actions.services', 'pm2-restart' => 'actions.services', 'f2b-unban' => 'actions.services',
    'site' => 'actions.system', 'certbot:renew' => 'actions.system', 'apt-clean' => 'actions.system', 'journal-vacuum' => 'actions.system',
    'apt-upgrade' => 'actions.system', 'backup-restore' => 'actions.system', 'reboot' => 'actions.system', 'offsite-test' => 'actions.system', 'harden' => 'actions.system',
    'self-update' => 'panel.update', 'self-rollback' => 'panel.update', 'web-domain' => 'panel.update',
];

function role_rank(string $role): int
{
    return ROLES[$role]['rank'] ?? 0;
}

function role_label(string $role): string
{
    return ROLES[$role]['label'] ?? $role;
}

function can(?array $user, string $perm): bool
{
    if (!$user || !isset(PERMISSIONS[$perm])) {
        return false;
    }
    return role_rank($user['role']) >= role_rank(PERMISSIONS[$perm][0]);
}

/** @return string[] every permission of a role */
function role_permissions(string $role): array
{
    return array_keys(array_filter(PERMISSIONS, fn($p) => role_rank($role) >= role_rank($p[0])));
}

function action_permission(string $verb, array $args): ?string
{
    return ACTION_PERMS[$verb . ':' . ($args[0] ?? '')] ?? ACTION_PERMS[$verb] ?? null;
}

/**
 * Does this backup download hold the panel's own secrets? The "hostkeep" database (password hashes, encrypted
 * 2FA secrets and settings), the "hostkeep" file archive (/etc/hostkeep and the panel's .env with its key) and
 * a whole day (which contains both). Those need "backups.panel", the other ones "backups.download".
 */
function backup_holds_panel_secrets(string $db, ?string $archive, string $pre): bool
{
    if ($pre !== '') {
        return (bool) preg_match('/^\d{8}-\d{6}-hostkeep\.sql\.gz$/D', $pre);
    }
    if ($archive !== null) {
        return $archive === '' || $archive === 'hostkeep';
    }
    return $db === 'hostkeep';
}

/** Stops the API request with 403 when the permission is missing. */
function require_perm(array $user, string $perm): void
{
    if (!can($user, $perm)) {
        json_out(['error' => 'Accès refusé : ton rôle (' . role_label($user['role']) . ') ne permet pas cette opération.'], 403);
    }
}

/** May $actor edit / disable / delete the account $target? Super Admins manage everyone, others only lower ranks. */
function can_manage_user(array $actor, array $target): bool
{
    if (!can($actor, 'users.manage')) {
        return false;
    }
    return $actor['role'] === 'superadmin' || role_rank($actor['role']) > role_rank($target['role']);
}

/** Roles $actor may give to an account. */
function assignable_roles(array $actor): array
{
    if (!can($actor, 'users.manage')) {
        return [];
    }
    return array_keys(array_filter(ROLES, fn($r, $k) => $actor['role'] === 'superadmin' || $r['rank'] < role_rank($actor['role']), ARRAY_FILTER_USE_BOTH));
}
