<?php
declare(strict_types=1);

/**
 * What a pasted rclone section (Administration › Sauvegarde externe › « Autre ») may contain.
 *
 * rclone runs as root in the collector, and some of its options run programs or touch local files
 * (sftp « ssh », webdav « bearer_token_command », « *_file », the local / alias / union backends…).
 * So the section is an allow-list: known storage backends, and only options that carry data
 * (credentials, endpoint, region…). Checked when the setting is saved AND again when rclone.conf is
 * written, since the stored value may predate these rules. No dependency: unit-tested on its own.
 */

/** Remote storage backends that may be configured by hand. Local, alias, union, crypt, chunker… are refused. */
const RCLONE_TYPES = [
    's3', 'b2', 'googlecloudstorage', 'azureblob', 'swift', 'oracleobjectstorage', 'qingstor', 'storj',
    'drive', 'dropbox', 'onedrive', 'box', 'pcloud', 'mega', 'koofr', 'jottacloud', 'yandex', 'hidrive', 'protondrive', 'seafile',
    'webdav', 'sftp', 'ftp', 'smb',
];

/** Options that only carry data. Anything else is refused. */
const RCLONE_KEYS = [
    'type', 'provider', 'env_auth',
    // object storage
    'access_key_id', 'secret_access_key', 'session_token', 'region', 'endpoint', 'location_constraint', 'acl', 'bucket_acl',
    'storage_class', 'server_side_encryption', 'sse_kms_key_id', 'force_path_style', 'v2_auth', 'no_check_bucket',
    'account', 'key', 'hard_delete', 'project_number', 'bucket_policy_only', 'namespace', 'compartment', 'tenancy', 'tier',
    'access_grant', 'satellite_address', 'api_key', 'passphrase',
    // swift / openstack
    'user', 'user_id', 'auth', 'auth_version', 'domain', 'tenant', 'tenant_id', 'tenant_domain', 'storage_url', 'auth_token', 'endpoint_type',
    // OAuth (drive, dropbox, onedrive, box…): token from « rclone config » on another machine
    'client_id', 'client_secret', 'token', 'scope', 'root_folder_id', 'team_drive', 'drive_id', 'drive_type', 'library',
    // webdav, sftp, ftp, smb
    'url', 'vendor', 'pass', 'bearer_token', 'host', 'port', 'key_pem', 'tls', 'explicit_tls',
    // tuning
    'chunk_size', 'upload_cutoff', 'upload_concurrency',
];

/** Known dangerous options, named in the error so the refusal is understood. */
const RCLONE_DANGEROUS_KEY = '/command|_file$|^ssh$|_path$|socket|^remote$|^upstreams$|_url$|^config_|_agent$/';

/**
 * Parses and checks a pasted rclone section.
 * @return array{lines: string[], error: ?string} normalized « key = value » lines (section title and comments dropped)
 */
function rclone_config_parse(string $raw): array
{
    $lines = [];
    $seen = [];
    $type = null;
    foreach (preg_split('/\r?\n/', $raw) as $l) {
        $l = trim($l);
        if ($l === '' || $l[0] === '#' || $l[0] === ';' || preg_match('/^\[[^\]]*\]$/D', $l)) {
            continue;
        }
        if (!preg_match('/^([a-z0-9_]{1,60})\s*=\s*(.*)$/D', $l, $m)) {
            return ['lines' => [], 'error' => 'Ligne non reconnue (attendu « clé = valeur ») : ' . mb_substr($l, 0, 60)];
        }
        [, $key, $value] = $m;
        if (preg_match('/[\x00-\x1F\x7F]/', $value) || strlen($value) > 8000) {
            return ['lines' => [], 'error' => "Valeur invalide pour « $key »."];
        }
        if (!in_array($key, RCLONE_KEYS, true)) {
            return ['lines' => [], 'error' => preg_match(RCLONE_DANGEROUS_KEY, $key)
                ? "Option « $key » interdite : elle lancerait un programme ou lirait un fichier local en root."
                : "Option « $key » non prise en charge par Hostkeep (seules les options d'identification et d'adresse sont acceptées)."];
        }
        if (isset($seen[$key])) {
            return ['lines' => [], 'error' => "Option « $key » présente deux fois."];
        }
        $seen[$key] = true;
        if ($key === 'type') {
            if (!in_array($value, RCLONE_TYPES, true)) {
                return ['lines' => [], 'error' => "Type « $value » non accepté. Types possibles : " . implode(', ', RCLONE_TYPES) . '.'];
            }
            $type = $value;
        }
        $lines[] = "$key = $value";
    }
    if ($type === null) {
        return ['lines' => [], 'error' => 'Il manque la ligne « type = … » (ex. type = drive).'];
    }
    return ['lines' => $lines, 'error' => null];
}
