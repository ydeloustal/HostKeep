<?php
declare(strict_types=1);

/**
 * Minimal SMTP client (no dependency): STARTTLS or implicit TLS, AUTH PLAIN/LOGIN, plain-text UTF-8 mails.
 * Settings: Administration › Paramètres › E-mails (SMTP).
 */
require_once __DIR__ . '/settings.php';

function mail_configured(): bool
{
    return setting('smtp.host') !== '' && setting('smtp.from_email') !== '';
}

/** @param string|string[] $to @throws RuntimeException with a readable message */
function send_mail(string|array $to, string $subject, string $body): void
{
    if (!mail_configured()) {
        throw new RuntimeException('Aucun serveur SMTP configuré (Administration › Paramètres › E-mails).');
    }
    $to = array_values(array_filter((array) $to, 'valid_email'));
    if (!$to) {
        throw new RuntimeException('Aucun destinataire valide.');
    }
    $host = setting('smtp.host');
    $port = setting_int('smtp.port') ?: 587;
    $enc = setting('smtp.encryption');
    $from = setting('smtp.from_email');
    $fromName = preg_replace('/[\r\n"]/', '', setting('smtp.from_name') ?: panel_name());
    $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host]]);
    $remote = ($enc === 'tls' ? 'tls://' : 'tcp://') . $host . ':' . $port;
    $fp = @stream_socket_client($remote, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);
    if (!$fp) {
        throw new RuntimeException("Connexion à $host:$port impossible : $errstr");
    }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): array {
        $lines = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $lines .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        return [(int) substr($lines, 0, 3), trim($lines)];
    };
    $cmd = static function (string $c, array $expect, ?string $shown = null) use ($fp, $read): string {
        fwrite($fp, $c . "\r\n");
        [$code, $msg] = $read();
        if (!in_array($code, $expect, true)) {
            throw new RuntimeException('Le serveur SMTP a refusé « ' . ($shown ?? $c) . " » : $msg");
        }
        return $msg;
    };
    try {
        [$code, $msg] = $read();
        if ($code !== 220) {
            throw new RuntimeException("Accueil SMTP inattendu : $msg");
        }
        $me = preg_replace('/[^A-Za-z0-9.-]/', '', (string) (gethostname() ?: 'localhost')) ?: 'localhost';
        $ehlo = $cmd("EHLO $me", [250]);
        if ($enc === 'starttls') {
            if (!preg_match('/^250[ -]STARTTLS/mi', $ehlo)) {
                throw new RuntimeException('Le serveur ne propose pas STARTTLS : choisis « TLS implicite » ou « Aucun ».');
            }
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new RuntimeException('Négociation TLS impossible (certificat invalide ?).');
            }
            $ehlo = $cmd("EHLO $me", [250]);
        }
        $user = setting('smtp.username');
        if ($user !== '') {
            $pass = setting('smtp.password');
            if (preg_match('/^250[ -]AUTH.*\bPLAIN\b/mi', $ehlo)) {
                $cmd('AUTH PLAIN ' . base64_encode("\0$user\0$pass"), [235], 'AUTH PLAIN');
            } else {
                $cmd('AUTH LOGIN', [334]);
                $cmd(base64_encode($user), [334], 'identifiant');
                $cmd(base64_encode($pass), [235], 'mot de passe');
            }
        }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($to as $rcpt) {
            $cmd("RCPT TO:<$rcpt>", [250, 251]);
        }
        $cmd('DATA', [354]);
        $domain = substr(strrchr($from, '@') ?: '@localhost', 1);
        $headers = [
            'Date: ' . date(DATE_RFC2822),
            'From: ' . mb_encode_mimeheader($fromName, 'UTF-8', 'B') . " <$from>",
            'To: ' . implode(', ', $to),
            'Subject: ' . mb_encode_mimeheader(preg_replace('/[\r\n]/', ' ', '[' . panel_name() . '] ' . $subject), 'UTF-8', 'B'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . "@$domain>",
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'Auto-Submitted: auto-generated',
        ];
        $body .= "\n-- \n" . panel_name() . ' · ' . base_url() . "\n";
        $data = implode("\r\n", $headers) . "\r\n\r\n" . rtrim(chunk_split(base64_encode(str_replace("\n", "\r\n", str_replace("\r\n", "\n", $body))), 76, "\r\n"));
        $cmd($data . "\r\n.", [250], 'contenu du message');
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}
