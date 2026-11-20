<?php
declare(strict_types=1);

/**
 * Panel settings, stored in the "settings" table and edited from Administration › Paramètres.
 * Each group is guarded by one permission (app/src/rbac.php). Values are validated here, never trusted
 * from the browser. Secrets (SMTP password) are encrypted with HOSTKEEP_APP_KEY and never sent back.
 *
 * Field: key => [type, default, label, help, extra]
 *   types: text, email, emails, int, bool, select, time, password, cidrs, lines, secrettext
 *   (password and secrettext are stored encrypted; extra 'show' => [field, [values]] shows a field only for some choices)
 */
function settings_schema(): array
{
    return [
        'general' => ['label' => 'Général', 'perm' => 'settings.general', 'fields' => [
            'general.panel_name' => ['text', 'Hostkeep', 'Nom du panel', 'Affiché dans le titre des pages, sur la page de connexion et dans les e-mails.', ['max' => 40]],
            'general.base_url' => ['text', '', 'Adresse publique du panel', 'Utilisée dans les liens des e-mails (réinitialisation, invitation). Vide = https://HOSTKEEP_DOMAIN.', ['max' => 200, 'placeholder' => 'https://admin.example.com']],
        ]],
        'smtp' => ['label' => 'E-mails (SMTP)', 'perm' => 'settings.smtp', 'fields' => [
            'smtp.host' => ['text', '', 'Serveur SMTP', 'Vide = envoi d\'e-mails désactivé (mot de passe oublié, invitations, alertes).', ['max' => 190, 'placeholder' => 'smtp.example.com']],
            'smtp.port' => ['int', '587', 'Port', '587 (STARTTLS), 465 (TLS) ou 25.', ['min' => 1, 'max' => 65535]],
            'smtp.encryption' => ['select', 'starttls', 'Chiffrement', '', ['options' => ['starttls' => 'STARTTLS', 'tls' => 'TLS implicite (465)', 'none' => 'Aucun (déconseillé)']]],
            'smtp.username' => ['text', '', 'Identifiant', 'Vide = pas d\'authentification.', ['max' => 190]],
            'smtp.password' => ['password', '', 'Mot de passe', 'Stocké chiffré. Laisse vide pour garder l\'actuel.', []],
            'smtp.from_email' => ['email', '', 'Adresse d\'expédition', '', ['placeholder' => 'hostkeep@example.com']],
            'smtp.from_name' => ['text', 'Hostkeep', 'Nom d\'expéditeur', '', ['max' => 80]],
        ]],
        'notifications' => ['label' => 'Alertes', 'perm' => 'settings.notifications', 'fields' => [
            'notify.level' => ['select', 'crit', 'Niveau minimum', 'Vérifié toutes les 5 minutes : un seul message par nouvelle alerte, puis un quand elle est résolue, sur chaque canal configuré ci-dessous.', ['options' => ['crit' => 'Critiques seulement', 'warn' => 'Critiques et avertissements']]],
            'notify.enabled' => ['bool', '0', 'Par e-mail', 'Nécessite le SMTP (onglet E-mails).', []],
            'notify.recipients' => ['emails', '', 'Destinataires des e-mails', 'Adresses séparées par des virgules. Vide = tous les Super Admins et Administrateurs actifs ayant un e-mail.', []],
            'notify.discord_url' => ['password', '', 'Discord : URL du webhook', 'Sur Discord : Paramètres du salon › Intégrations › Webhooks › Nouveau webhook › Copier l\'URL. Stockée chiffrée. Vide = pas de Discord.', ['placeholder' => 'https://discord.com/api/webhooks/…']],
            'notify.telegram_token' => ['password', '', 'Telegram : jeton du bot', 'Crée un bot avec @BotFather (/newbot), puis écris-lui un message. Stocké chiffré.', ['placeholder' => '123456789:AA…']],
            'notify.telegram_chat' => ['text', '', 'Telegram : identifiant de la conversation', 'Ton identifiant (donné par @userinfobot), celui d\'un groupe (commence par -) ou @nom_du_canal.', ['max' => 64, 'placeholder' => '123456789']],
            'notify.webhook_url' => ['password', '', 'Webhook générique (JSON)', 'Reçoit un POST JSON : event, server, panel, url, new[], resolved[], text. Pour n8n, Zapier, Slack (via un relais), ntfy, Gotify… Stocké chiffré.', ['placeholder' => 'https://exemple.com/hook']],
        ]],
        'monitor' => ['label' => 'Surveillance externe', 'perm' => 'settings.notifications', 'fields' => [
            'monitor.kind' => ['select', 'healthchecks', 'Service', '', ['options' => ['healthchecks' => 'Healthchecks.io (ou instance auto-hébergée)', 'kuma' => 'Uptime Kuma (moniteur de type « Push »)', 'generic' => 'Autre (URL appelée quand tout va bien)']]],
            'monitor.ping_url' => ['password', '', 'URL de ping', 'Appelée chaque minute par le collecteur. Si elle ne l\'est plus (serveur éteint, plus de réseau, collecteur bloqué), c\'est le service qui te prévient. Règle-y une période de 1 minute et une marge de 5 minutes. Stockée chiffrée. Vide = désactivé.', ['placeholder' => 'https://hc-ping.com/…']],
        ]],
        'backups' => ['label' => 'Sauvegardes', 'perm' => 'settings.backups', 'fields' => [
            'backup.enabled' => ['bool', '1', 'Sauvegarde automatique', 'Chaque nuit : un dump compressé de chaque base MariaDB, plus les fichiers si l\'option ci-dessous est cochée.', []],
            'backup.time' => ['time', '03:10', 'Heure de la sauvegarde', 'Heure du serveur. Appliquée dans la minute.', []],
            'backup.keep_days' => ['int', '7', 'Conservation des bases (jours)', 'Les sauvegardes plus anciennes sont supprimées.', ['min' => 1, 'max' => 365]],
            'backup.files_enabled' => ['bool', '1', 'Sauvegarder aussi les fichiers', '/var/www, /etc/apache2, /etc/nginx, /etc/letsencrypt et /etc/hostkeep, en archives .tar.gz. Sautée (avec une alerte) s\'il ne reste pas assez de place sur le disque.', []],
            'backup.files_keep_days' => ['int', '3', 'Conservation des fichiers (jours)', 'Les archives de fichiers prennent plus de place : garde-les moins longtemps ici, la copie externe a sa propre durée. Jamais plus que celle des bases.', ['min' => 1, 'max' => 365]],
            'backup.exclude' => ['lines', "node_modules\n.cache\n*.log\n*.tmp", 'Fichiers exclus', 'Un motif par ligne (syntaxe tar, ex. node_modules ou */cache/*). Les dossiers qui contiennent un fichier CACHEDIR.TAG sont toujours exclus.', []],
        ]],
        'offsite' => ['label' => 'Sauvegarde externe', 'perm' => 'settings.backups', 'fields' => [
            'offsite.enabled' => ['bool', '0', 'Copier les sauvegardes hors du serveur', 'Avec rclone, juste après chaque sauvegarde. Une copie qui échoue est retentée toutes les heures.', []],
            'offsite.type' => ['select', 's3', 'Destination', '', ['options' => ['s3' => 'Stockage S3 (AWS, Scaleway, OVH, Backblaze, MinIO…)', 'sftp' => 'SFTP (un autre serveur, un NAS)', 'rclone' => 'Autre, avec une configuration rclone (Google Drive, Dropbox, OneDrive…)']]],
            'offsite.s3_provider' => ['select', 'Other', 'Fournisseur S3', '', ['options' => ['AWS' => 'Amazon S3', 'Scaleway' => 'Scaleway', 'Wasabi' => 'Wasabi', 'Minio' => 'MinIO', 'Other' => 'Autre compatible S3 (OVH, Backblaze B2, Garage…)'], 'show' => ['offsite.type', ['s3']]]],
            'offsite.s3_endpoint' => ['text', '', 'Point d\'accès (endpoint)', 'Vide pour Amazon S3.', ['max' => 190, 'placeholder' => 's3.fr-par.scw.cloud', 'show' => ['offsite.type', ['s3']]]],
            'offsite.s3_region' => ['text', '', 'Région', '', ['max' => 40, 'placeholder' => 'fr-par', 'show' => ['offsite.type', ['s3']]]],
            'offsite.s3_access_key' => ['text', '', 'Clé d\'accès (access key)', '', ['max' => 200, 'show' => ['offsite.type', ['s3']]]],
            'offsite.s3_secret_key' => ['password', '', 'Clé secrète (secret key)', 'Stockée chiffrée. Laisse vide pour garder l\'actuelle.', ['show' => ['offsite.type', ['s3']]]],
            'offsite.sftp_host' => ['text', '', 'Serveur SFTP', '', ['max' => 190, 'placeholder' => 'backup.example.com', 'show' => ['offsite.type', ['sftp']]]],
            'offsite.sftp_port' => ['int', '22', 'Port', '', ['min' => 1, 'max' => 65535, 'show' => ['offsite.type', ['sftp']]]],
            'offsite.sftp_user' => ['text', '', 'Utilisateur', '', ['max' => 64, 'show' => ['offsite.type', ['sftp']]]],
            'offsite.sftp_password' => ['password', '', 'Mot de passe', 'Ou une clé privée ci-dessous (recommandé). Stocké chiffré.', ['show' => ['offsite.type', ['sftp']]]],
            'offsite.sftp_key' => ['secrettext', '', 'Clé privée SSH', 'Le contenu complet de la clé (OpenSSH ou PEM), sans phrase de passe. Stockée chiffrée, jamais réaffichée.', ['show' => ['offsite.type', ['sftp']]]],
            'offsite.rclone_config' => ['secrettext', '', 'Configuration rclone', 'Les lignes « clé = valeur » d\'une section de rclone.conf (le titre [nom] est ignoré). Pour Google Drive : lance « rclone config » sur ton ordinateur, puis colle ici la section obtenue. Stockée chiffrée.', ['show' => ['offsite.type', ['rclone']]]],
            'offsite.path' => ['text', '', 'Dossier distant', 'S3 : le nom du bucket, puis un dossier si tu veux. Les sauvegardes y sont rangées par serveur, puis par jour.', ['max' => 200, 'placeholder' => 'mon-bucket/hostkeep']],
            'offsite.keep_days' => ['int', '30', 'Conservation à distance (jours)', 'Les copies distantes plus anciennes sont supprimées.', ['min' => 1, 'max' => 3650]],
            'offsite.crypt_password' => ['password', '', 'Phrase de chiffrement (recommandé)', 'Les fichiers sont chiffrés avant l\'envoi (rclone crypt) : l\'hébergeur distant ne voit ni les noms ni le contenu. Garde-la ailleurs : sans elle, les copies sont illisibles. La changer rend les anciennes copies illisibles avec la nouvelle.', []],
            'offsite.bwlimit' => ['text', '', 'Limite de débit', 'Ex. 2M pour 2 Mo/s. Vide = sans limite.', ['max' => 10, 'placeholder' => '2M']],
        ]],
        'security' => ['label' => 'Sécurité', 'perm' => 'settings.security', 'fields' => [
            'security.session_timeout' => ['int', (string) max(5, intdiv((int) env('HOSTKEEP_SESSION_TIMEOUT', '1800'), 60)), 'Déconnexion après inactivité (minutes)', '', ['min' => 5, 'max' => 1440]],
            'security.password_min_length' => ['int', '12', 'Longueur minimale des mots de passe', 'S\'applique aux prochains changements de mot de passe.', ['min' => 8, 'max' => 128]],
            'security.max_attempts' => ['int', '5', 'Échecs de connexion avant blocage', 'Par adresse IP et par compte.', ['min' => 3, 'max' => 50]],
            'security.lockout_minutes' => ['int', '15', 'Durée du blocage (minutes)', '', ['min' => 1, 'max' => 1440]],
            'security.2fa_required' => ['select', 'admin', 'Double authentification obligatoire', 'Les comptes concernés qui ne l\'ont pas encore l\'activent dès leur prochaine page (QR code à scanner, puis un code). Les autres peuvent l\'activer dans Mon compte.', ['options' => ['admin' => 'Pour les Administrateurs et Super Admins (recommandé)', 'all' => 'Pour tous les comptes', 'none' => 'Pour personne (chacun choisit)']]],
            'security.password_reset' => ['bool', '1', 'Autoriser « Mot de passe oublié »', 'Nécessite un serveur SMTP configuré.', []],
            'security.ip_allowlist' => ['cidrs', '', 'Adresses IP autorisées', 'Une IP ou un réseau (CIDR) par ligne, ex. 203.0.113.10 ou 10.8.0.0/24. Vide = accès depuis partout. Ton IP actuelle doit en faire partie.', []],
        ]],
    ];
}

function settings_fields(): array
{
    $all = [];
    foreach (settings_schema() as $g) {
        $all += $g['fields'];
    }
    return $all;
}

/** Raw stored values, cached for the request. */
function settings_all(bool $reload = false): array
{
    static $cache = null;
    if ($cache === null || $reload) {
        try {
            $cache = db()->query('SELECT name, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Throwable $e) {
            $cache = [];   // table missing (old schema): defaults
        }
    }
    return $cache;
}

function setting(string $key): string
{
    $f = settings_fields()[$key] ?? null;
    $v = settings_all()[$key] ?? null;
    if ($v === null) {
        return $f ? $f[1] : '';
    }
    return ($f && is_secret_type($f[0])) ? secret_decrypt($v) : $v;
}

function is_secret_type(string $type): bool
{
    return $type === 'password' || $type === 'secrettext';
}

function setting_int(string $key): int
{
    return (int) setting($key);
}

function setting_bool(string $key): bool
{
    return setting($key) === '1';
}

function panel_name(): string
{
    return setting('general.panel_name') ?: 'Hostkeep';
}

/** Public address used in e-mails. */
function base_url(): string
{
    $u = rtrim(setting('general.base_url'), '/');
    if ($u !== '') {
        return $u;
    }
    $d = env('HOSTKEEP_DOMAIN');
    $scheme = env('HOSTKEEP_HTTPS', 'yes') === 'no' ? 'http' : 'https';
    return $d !== '' ? "$scheme://$d" : (PHP_SAPI !== 'cli' ? (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') : 'http://localhost');
}

/** Settings of the groups the user may see, ready for the form (secrets are never returned). */
function settings_for(array $user): array
{
    $out = [];
    foreach (settings_schema() as $gid => $g) {
        if (!can($user, 'settings.view')) {
            continue;
        }
        $fields = [];
        foreach ($g['fields'] as $key => [$type, $default, $label, $help, $extra]) {
            $raw = settings_all()[$key] ?? null;
            $secret = is_secret_type($type);
            $fields[] = ['key' => $key, 'type' => $type, 'label' => $label, 'help' => $help, 'default' => $secret ? '' : $default,
                'value' => $secret ? '' : ($raw ?? $default), 'set' => $secret ? ($raw ?? '') !== '' : null] + $extra;
        }
        $out[] = ['id' => $gid, 'label' => $g['label'], 'editable' => can($user, $g['perm']), 'fields' => $fields];
    }
    return $out;
}

/**
 * Validates and stores the values of one group. @return array list of errors (field => message), empty on success.
 */
function settings_save(string $group, array $in, array $user): array
{
    $g = settings_schema()[$group] ?? null;
    if (!$g) {
        return ['_' => 'Groupe de paramètres inconnu.'];
    }
    $errors = [];
    $clean = [];
    foreach ($g['fields'] as $key => [$type, $default, $label, $help, $extra]) {
        if (is_secret_type($type) && ($in["$key#clear"] ?? '') === '1') {
            $clean[$key] = '';   // "remove the stored value" box
            continue;
        }
        if (!array_key_exists($key, $in)) {
            continue;
        }
        $v = is_scalar($in[$key]) ? ($type === 'secrettext' || $type === 'lines' ? (string) $in[$key] : trim((string) $in[$key])) : '';
        $err = null;
        switch ($type) {
            case 'text':
                if (mb_strlen($v) > ($extra['max'] ?? 255) || preg_match('/[\x00-\x1F\x7F]/u', $v)) {
                    $err = 'Valeur invalide ou trop longue.';
                } elseif ($key === 'general.base_url' && $v !== '' && !preg_match('#^https?://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._~/-]*)?$#D', $v)) {
                    $err = 'Adresse invalide (ex. https://admin.example.com).';
                } elseif ($key === 'smtp.host' && $v !== '' && !preg_match('/^[A-Za-z0-9.-]{1,190}$/D', $v)) {
                    $err = 'Nom d\'hôte invalide.';
                } elseif ($key === 'general.panel_name' && $v === '') {
                    $err = 'Obligatoire.';
                } elseif ($v !== '' && ($re = OFFSITE_TEXT_RULES[$key] ?? null) && !preg_match($re[0], $v)) {
                    $err = $re[1];
                }
                break;
            case 'lines':
                $list = array_values(array_filter(array_map('trim', preg_split('/\R/', $v)), 'strlen'));
                foreach ($list as $l) {
                    if (strlen($l) > 200 || preg_match('/[\x00-\x1F\x7F]/', $l) || str_starts_with($l, '-')) {
                        $err = "Ligne invalide : $l";
                    }
                }
                $v = implode("\n", $list);
                break;
            case 'secrettext':
                $v = trim(str_replace("\r", '', $v));
                if ($v === '') {
                    continue 2;   // keep the stored one
                }
                $err = strlen($v) > 20000 ? 'Trop long.' : secrettext_problem($key, $v);
                break;
            case 'email':
                if ($v !== '' && !valid_email($v)) {
                    $err = 'Adresse e-mail invalide.';
                }
                break;
            case 'emails':
                $list = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', $v)), 'strlen'));
                foreach ($list as $e) {
                    if (!valid_email($e)) {
                        $err = "Adresse invalide : $e";
                    }
                }
                $v = implode(', ', $list);
                break;
            case 'int':
                if (!preg_match('/^\d{1,6}$/D', $v) || (int) $v < ($extra['min'] ?? 0) || (int) $v > ($extra['max'] ?? PHP_INT_MAX)) {
                    $err = sprintf('Nombre entre %d et %d.', $extra['min'] ?? 0, $extra['max'] ?? 999999);
                }
                $v = (string) (int) $v;
                break;
            case 'bool':
                $v = in_array($v, ['1', 'true', 'on'], true) ? '1' : '0';
                break;
            case 'select':
                if (!isset($extra['options'][$v])) {
                    $err = 'Choix invalide.';
                }
                break;
            case 'time':
                if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/D', $v)) {
                    $err = 'Heure au format HH:MM.';
                }
                break;
            case 'password':
                if ($v === '') {
                    continue 2;   // keep the stored one
                }
                if (strlen($v) > 500 || preg_match('/[\x00-\x1F\x7F]/', $v)) {
                    $err = 'Trop long ou caractères invalides.';
                } elseif (($re = SECRET_RULES[$key] ?? null) && !preg_match($re[0], $v)) {
                    $err = $re[1];
                }
                break;
            case 'cidrs':
                $list = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $v)), 'strlen'));
                foreach ($list as $c) {
                    if (!valid_cidr($c)) {
                        $err = "Adresse ou réseau invalide : $c";
                    }
                }
                if (!$err && $list && PHP_SAPI !== 'cli' && !ip_in_list(client_ip(), $list)) {
                    $err = 'Ton adresse IP actuelle (' . client_ip() . ') n\'est pas dans la liste : tu serais bloqué.';
                }
                $v = implode("\n", $list);
                break;
        }
        if ($err) {
            $errors[$key] = $err;
        } else {
            $clean[$key] = $v;
        }
    }
    if ($errors) {
        return $errors;
    }
    $old = settings_all();
    $st = db()->prepare('REPLACE INTO settings (name, value, updated_at, updated_by) VALUES (?, ?, ?, ?)');
    $changed = [];
    foreach ($clean as $key => $v) {
        $isSecret = is_secret_type(settings_fields()[$key][0]);
        if (!$isSecret && ($old[$key] ?? settings_fields()[$key][1]) === $v) {
            continue;
        }
        $st->execute([$key, $isSecret && $v !== '' ? secret_encrypt($v) : $v, time(), $user['username']]);
        $changed[] = $key;
    }
    settings_all(true);
    if ($changed) {
        audit('settings ' . $group . ' : ' . implode(', ', $changed));
    }
    return [];
}

/** Secret fields with a known shape (checked before being stored). */
const SECRET_RULES = [
    'notify.discord_url' => ['#^https://(discord\.com|discordapp\.com|ptb\.discord\.com|canary\.discord\.com)/api/webhooks/\d+/[A-Za-z0-9_-]+$#D', 'Ce n\'est pas une URL de webhook Discord (https://discord.com/api/webhooks/…).'],
    'notify.telegram_token' => ['/^\d{5,15}:[A-Za-z0-9_-]{30,64}$/D', 'Ce n\'est pas un jeton de bot Telegram (123456789:AA…).'],
    'notify.webhook_url' => ['~^https?://[^\s/?#]+[^\s]*$~D', 'Adresse invalide (https://…).'],
    'monitor.ping_url' => ['~^https?://[^\s/?#]+[^\s]*$~D', 'Adresse invalide (https://…).'],
];

/** Text fields that end up in rclone.conf or on the rclone command line: strict formats. */
const OFFSITE_TEXT_RULES = [
    'offsite.s3_endpoint' => ['/^(https?:\/\/)?[A-Za-z0-9.-]{1,180}(:\d{1,5})?\/?$/D', 'Adresse invalide (ex. s3.fr-par.scw.cloud).'],
    'offsite.s3_region' => ['/^[A-Za-z0-9-]{1,40}$/D', 'Région invalide (ex. fr-par).'],
    'offsite.s3_access_key' => ['/^[A-Za-z0-9+\/=._-]{1,200}$/D', 'Clé d\'accès invalide.'],
    'offsite.sftp_host' => ['/^[A-Za-z0-9.:-]{1,190}$/D', 'Nom d\'hôte ou adresse IP invalide.'],
    'offsite.sftp_user' => ['/^[A-Za-z0-9._-]{1,64}$/D', 'Nom d\'utilisateur invalide.'],
    'offsite.path' => ['/^(?!.*\.\.)[A-Za-z0-9._-][A-Za-z0-9._\/-]{0,199}$/D', 'Chemin invalide : lettres, chiffres, « . _ - / », sans « .. ».'],
    'offsite.bwlimit' => ['/^\d{1,6}[KMGkmg]?$/D', 'Ex. 2M ou 500K.'],
    'notify.telegram_chat' => ['/^(-?\d{1,20}|@[A-Za-z0-9_]{5,32})$/D', 'Un nombre (ex. 123456789 ou -100123…) ou @nom_du_canal.'],
];

function secrettext_problem(string $key, string $v): ?string
{
    if ($key === 'offsite.sftp_key' && !preg_match('/-----BEGIN [A-Z ]*PRIVATE KEY-----.+-----END [A-Z ]*PRIVATE KEY-----/s', $v)) {
        return 'Ce n\'est pas une clé privée (-----BEGIN … PRIVATE KEY-----).';
    }
    if ($key === 'offsite.rclone_config') {
        $type = false;
        foreach (preg_split('/\n/', $v) as $l) {
            $l = trim($l);
            if ($l === '' || $l[0] === '#' || $l[0] === ';' || preg_match('/^\[[^\]]*\]$/', $l)) {
                continue;
            }
            if (!preg_match('/^([a-z0-9_]{1,60})\s*=\s*[^\x00-\x1F\x7F]*$/D', $l, $m)) {
                return "Ligne non reconnue (attendu « clé = valeur ») : " . mb_substr($l, 0, 60);
            }
            $type = $type || $m[1] === 'type';
        }
        if (!$type) {
            return 'Il manque la ligne « type = … » (ex. type = drive).';
        }
    }
    return null;
}

function valid_email(string $e): bool
{
    return strlen($e) <= 190 && filter_var($e, FILTER_VALIDATE_EMAIL) !== false && !preg_match('/[\r\n]/', $e);
}

function valid_cidr(string $c): bool
{
    [$ip, $bits] = array_pad(explode('/', $c, 2), 2, null);
    $bin = @inet_pton((string) $ip);
    if ($bin === false) {
        return false;
    }
    return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($bin) * 8);
}

function ip_in_list(string $ip, array $list): bool
{
    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }
    foreach ($list as $c) {
        [$net, $bits] = array_pad(explode('/', $c, 2), 2, null);
        $nb = @inet_pton((string) $net);
        if ($nb === false || strlen($nb) !== strlen($bin)) {
            continue;
        }
        $bits = $bits === null ? strlen($nb) * 8 : (int) $bits;
        $bytes = intdiv($bits, 8);
        $rest = $bits % 8;
        if (substr($bin, 0, $bytes) !== substr($nb, 0, $bytes)) {
            continue;
        }
        if ($rest === 0 || ((ord($bin[$bytes]) ^ ord($nb[$bytes])) & (0xFF << (8 - $rest)) & 0xFF) === 0) {
            return true;
        }
    }
    return false;
}

/** Stops the request when an IP allow-list is set and the client is not in it. */
function enforce_ip_allowlist(bool $json = false): void
{
    $list = array_filter(explode("\n", setting('security.ip_allowlist')), 'strlen');
    if (!$list || ip_in_list(client_ip(), $list)) {
        return;
    }
    if ($json) {
        json_out(['error' => 'Accès refusé depuis cette adresse IP.'], 403);
    }
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Accès refusé depuis cette adresse IP (' . client_ip() . ").\n";
    exit;
}

/* ---------- secrets (AES-256-GCM, key = HOSTKEEP_APP_KEY from .env) ---------- */

function app_key(): ?string
{
    $k = base64_decode(env('HOSTKEEP_APP_KEY'), true);
    return $k !== false && strlen($k) === 32 ? $k : null;
}

function secret_encrypt(string $plain): string
{
    $key = app_key();
    if ($plain === '' || $key === null) {
        return $plain === '' ? '' : 'plain:' . base64_encode($plain);
    }
    $iv = random_bytes(12);
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $c);
}

function secret_decrypt(string $stored): string
{
    if ($stored === '') {
        return '';
    }
    if (str_starts_with($stored, 'plain:')) {
        return (string) base64_decode(substr($stored, 6));
    }
    $key = app_key();
    $raw = base64_decode(substr($stored, 3), true);
    if ($key === null || !str_starts_with($stored, 'v1:') || $raw === false || strlen($raw) < 29) {
        return '';
    }
    $p = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $p === false ? '' : $p;
}
