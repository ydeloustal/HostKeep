<?php
declare(strict_types=1);

/**
 * Alert channels (Administration › Paramètres › Alertes): e-mail, Discord, Telegram, generic webhook (JSON),
 * and the external heartbeat ping (Administration › Paramètres › Surveillance externe).
 * One message is built once (notify_message) and sent to every configured channel.
 */
require_once __DIR__ . '/mailer.php';

if (!defined('TELEGRAM_API')) {
    define('TELEGRAM_API', 'https://api.telegram.org');
}

const CHANNEL_LABELS = ['mail' => 'E-mail', 'discord' => 'Discord', 'telegram' => 'Telegram', 'webhook' => 'Webhook'];

/** @return string[] the channels ready to send (configured, and e-mail switched on) */
function notify_channels(): array
{
    $c = [];
    if (setting_bool('notify.enabled') && mail_configured()) {
        $c[] = 'mail';
    }
    if (setting('notify.discord_url') !== '') {
        $c[] = 'discord';
    }
    if (setting('notify.telegram_token') !== '' && setting('notify.telegram_chat') !== '') {
        $c[] = 'telegram';
    }
    if (setting('notify.webhook_url') !== '') {
        $c[] = 'webhook';
    }
    return $c;
}

function notify_mail_recipients(): array
{
    $to = array_filter(array_map('trim', explode(',', setting('notify.recipients'))));
    return $to ?: db()->query("SELECT email FROM users WHERE active = 1 AND email IS NOT NULL AND role IN ('superadmin', 'admin')")->fetchAll(PDO::FETCH_COLUMN);
}

/** @param array $new alerts that appeared @param array $gone alerts that disappeared @return array{subject:string,text:string,payload:array} */
function notify_message(array $new, array $gone): array
{
    $host = (string) gethostname();
    $label = ['crit' => 'CRITIQUE', 'warn' => 'ATTENTION', 'info' => 'INFO'];
    $text = "Serveur $host — " . date('d/m/Y H:i') . "\n\n";
    if ($new) {
        $text .= "Nouvelles alertes :\n" . implode("\n", array_map(fn($a) => "  [{$label[$a['level']]}] {$a['text']}", $new)) . "\n\n";
    }
    if ($gone) {
        $text .= "Résolues :\n" . implode("\n", array_map(fn($a) => "  ✓ {$a['text']}", $gone)) . "\n\n";
    }
    $text .= 'Ouvrir le panel : ' . base_url() . "/\n";
    $subject = $new ? count($new) . " nouvelle(s) alerte(s) sur $host" : "Alertes résolues sur $host";
    $strip = fn($a) => ['level' => $a['level'], 'text' => $a['text'], 'section' => $a['section'] ?? null];
    $payload = ['event' => 'alerts', 'server' => $host, 'panel' => panel_name(), 'url' => base_url() . '/', 'time' => time(),
        'new' => array_values(array_map($strip, $new)), 'resolved' => array_values(array_map($strip, $gone)), 'text' => $text];
    return ['subject' => $subject, 'text' => $text, 'payload' => $payload];
}

/** Sends to every channel given. @return array<string, string> channel => error (empty when all went through) */
function notify_send(array $msg, ?array $channels = null): array
{
    $errors = [];
    foreach ($channels ?? notify_channels() as $ch) {
        try {
            match ($ch) {
                'mail' => send_mail(notify_mail_recipients() ?: throw new RuntimeException('aucun destinataire (renseigne-en, ou un e-mail sur un compte administrateur)'),
                    $msg['subject'], $msg['text']),
                'discord' => http_post_json(setting('notify.discord_url'),
                    ['username' => mb_substr(panel_name(), 0, 80), 'content' => mb_strimwidth("**{$msg['subject']}**\n" . $msg['text'], 0, 1990, '…')]),
                'telegram' => http_post_json(TELEGRAM_API . '/bot' . setting('notify.telegram_token') . '/sendMessage',
                    ['chat_id' => setting('notify.telegram_chat'), 'text' => mb_strimwidth($msg['subject'] . "\n\n" . $msg['text'], 0, 4000, '…'), 'disable_web_page_preview' => true]),
                'webhook' => http_post_json(setting('notify.webhook_url'), $msg['payload']),
            };
        } catch (Throwable $e) {
            $errors[$ch] = $e->getMessage();
        }
    }
    return $errors;
}

/** POST JSON, 10 s max. @throws RuntimeException when unreachable or not 2xx (the URL, which holds the secret, is never in the message) */
function http_post_json(string $url, array $data): void
{
    [$code, $body, $err] = http_request($url, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($code < 200 || $code >= 300) {
        throw new RuntimeException($err !== '' ? $err : "réponse HTTP $code" . ($body !== '' ? ' : ' . mb_strimwidth(trim(strip_tags($body)), 0, 160, '…') : ''));
    }
}

/** @return array{0:int,1:string,2:string} HTTP code (0 = no answer), body, transport error */
function http_request(string $url, ?string $json = null, int $timeout = 10): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'Hostkeep/' . app_version()]);
    if ($json !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $body === false ? preg_replace('#https?://\S+#', '[url]', curl_error($ch)) : '';
    curl_close($ch);
    return [$code, is_string($body) ? mb_substr($body, 0, 2000) : '', (string) $err];
}

/* ---------- external heartbeat ("dead man's switch") ---------- */

/** The URL to call for this run: success, or failure when a collector task failed. */
function heartbeat_url(bool $ok): ?string
{
    $url = setting('monitor.ping_url');
    if ($url === '') {
        return null;
    }
    switch (setting('monitor.kind')) {
        case 'healthchecks':   // https://hc-ping.com/<uuid>  (+ /fail)
            return $ok ? $url : rtrim($url, '/') . '/fail';
        case 'kuma':           // https://kuma/api/push/<token>?status=up&msg=OK
            $base = strtok($url, '?');
            return $base . '?' . http_build_query(['status' => $ok ? 'up' : 'down', 'msg' => $ok ? 'OK' : 'collecte en échec', 'ping' => '']);
        default:               // any URL: called only when all is well
            return $ok ? $url : null;
    }
}

/** @return array{ok:bool,error:?string} */
function heartbeat_send(bool $ok): array
{
    $url = heartbeat_url($ok);
    if ($url === null) {
        return ['ok' => true, 'error' => null];
    }
    [$code, , $err] = http_request($url, null, 10);
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'error' => null];
    }
    return ['ok' => false, 'error' => $err !== '' ? $err : "réponse HTTP $code"];
}
