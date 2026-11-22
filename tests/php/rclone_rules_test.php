<?php
declare(strict_types=1);

/**
 * Allow-list of the pasted rclone section (app/src/rclone_rules.php). No framework: php tests/php/rclone_rules_test.php
 * Exit code 0 when every case passes.
 */
require __DIR__ . '/../../app/src/rclone_rules.php';

$fails = 0;
function check(string $name, bool $ok): void
{
    global $fails;
    echo ($ok ? 'ok   ' : 'FAIL ') . $name . "\n";
    $fails += $ok ? 0 : 1;
}
$accepted = fn(string $raw): bool => rclone_config_parse($raw)['error'] === null;

// accepted: real sections, as « rclone config » writes them
check('Google Drive (OAuth)', $accepted("[gdrive]\ntype = drive\nscope = drive\ntoken = {\"access_token\":\"ya29.x\",\"token_type\":\"Bearer\",\"expiry\":\"2026-01-01T00:00:00Z\"}\nteam_drive = \n"));
check('Backblaze B2', $accepted("type = b2\naccount = 0012ab\nkey = K001xyz\nhard_delete = true"));
check('WebDAV (Nextcloud)', $accepted("type = webdav\nurl = https://cloud.example.com/remote.php/dav/files/me\nvendor = nextcloud\nuser = me\npass = c2VjcmV0"));
check('SFTP with inline key', $accepted("type = sftp\nhost = backup.example.com\nuser = hk\nport = 2222\nkey_pem = -----BEGIN OPENSSH PRIVATE KEY-----\\nabc\\n-----END OPENSSH PRIVATE KEY-----"));
check('comments, blank lines and CRLF ignored', $accepted("# mine\r\n; also\r\n\r\n[x]\r\ntype = s3\r\nprovider = Scaleway\r\n"));
$p = rclone_config_parse("[x]\n  type=s3  \n# c\nregion = fr-par");
check('normalized lines, title and comments dropped', $p['lines'] === ['type = s3', 'region = fr-par']);

// refused: anything that runs a program or reaches local files as root
foreach ([
    'sftp external ssh command' => "type = sftp\nhost = h\nssh = sh -c 'id > /tmp/pwn'",
    'webdav bearer_token_command' => "type = webdav\nurl = https://x\nbearer_token_command = /bin/sh -c id",
    'sftp key_file (local file read)' => "type = sftp\nhost = h\nkey_file = /root/.ssh/id_ed25519",
    'drive service_account_file' => "type = drive\nservice_account_file = /etc/shadow",
    'sftp md5sum_command' => "type = sftp\nhost = h\nmd5sum_command = touch /x",
    'custom OAuth token_url' => "type = drive\ntoken_url = https://attacker.example/t",
    'local backend' => "type = local",
    'alias backend' => "type = alias\nremote = /etc/cron.d",
    'union backend' => "type = union\nupstreams = /etc /root",
    'crypt wrapping a local path' => "type = crypt\nremote = /etc",
    'unknown option' => "type = s3\nfoo = bar",
    'duplicate option' => "type = s3\nregion = a\nregion = b",
    'two types' => "type = s3\ntype = local",
    'no type' => "provider = AWS",
    'not key = value' => "type = s3\njust some text",
    'control character in value' => "type = s3\nregion = a\x1b[2J",
    'upper-case key' => "TYPE = s3",
] as $name => $raw) {
    check("refused: $name", !$accepted($raw));
}
check('dangerous key named as forbidden', str_contains((string) rclone_config_parse("type = sftp\nssh = x")['error'], 'interdite'));

echo $fails ? "\n$fails échec(s)\n" : "\nTous les tests passent.\n";
exit($fails ? 1 : 0);
