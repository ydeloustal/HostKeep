<?php
declare(strict_types=1);

/**
 * Two-factor authentication: TOTP (RFC 6238: HMAC-SHA1, 6 digits, 30 s), compatible with Aegis,
 * Google Authenticator, FreeOTP, 1Password, Bitwarden… plus single-use recovery codes.
 * The secret is stored encrypted (secret_encrypt, HOSTKEEP_APP_KEY); recovery codes are hashed.
 * No external library: the QR code is drawn here as SVG (qr_svg, at the end of this file).
 */

const TOTP_PERIOD = 30;
const TOTP_DIGITS = 6;
const RECOVERY_CODES = 10;
const B32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encode(string $bin): string
{
    $bits = '';
    foreach (str_split($bin) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= B32[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

function base32_decode(string $s): string
{
    $s = strtoupper(preg_replace('/[\s=-]/', '', $s));
    $bits = '';
    foreach (str_split($s) as $c) {
        $i = strpos(B32, $c);
        if ($i === false) {
            return '';
        }
        $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 8) as $byte) {
        if (strlen($byte) === 8) {
            $out .= chr(bindec($byte));
        }
    }
    return $out;
}

/** 160-bit random secret, base32 (what the user types if the QR code can't be scanned). */
function totp_new_secret(): string
{
    return base32_encode(random_bytes(20));
}

function totp_code(string $secretB32, int $step): string
{
    $h = hash_hmac('sha1', pack('J', $step), base32_decode($secretB32), true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 10 ** TOTP_DIGITS), TOTP_DIGITS, '0', STR_PAD_LEFT);
}

/**
 * Checks a 6-digit code (± 1 period for clock drift). A code already used (step <= $lastStep) is refused.
 * @return int|null the matching time step, to store as the new $lastStep
 */
function totp_verify(string $secretB32, string $code, int $lastStep = 0, ?int $now = null): ?int
{
    $code = preg_replace('/\s/', '', $code);
    if (!preg_match('/^\d{6}$/D', $code) || $secretB32 === '') {
        return null;
    }
    $t = intdiv($now ?? time(), TOTP_PERIOD);
    foreach ([$t, $t - 1, $t + 1] as $step) {
        if ($step > $lastStep && hash_equals(totp_code($secretB32, $step), $code)) {
            return $step;
        }
    }
    return null;
}

function otpauth_uri(string $account, string $issuer, string $secretB32): string
{
    $label = rawurlencode($issuer) . ':' . rawurlencode($account);
    // SHA1, 6 digits and 30 s are the defaults of every app: not repeated, to keep the QR code small
    return "otpauth://totp/$label?" . http_build_query(['secret' => $secretB32, 'issuer' => $issuer], '', '&', PHP_QUERY_RFC3986);
}

/** "abcd-efgh-jk" style codes (no 0/1/8/9 ambiguity: base32 lower case). */
function recovery_codes_new(): array
{
    $codes = [];
    for ($i = 0; $i < RECOVERY_CODES; $i++) {
        $c = strtolower(substr(base32_encode(random_bytes(7)), 0, 10));
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }
    return $codes;
}

function recovery_normalize(string $code): string
{
    return strtolower(preg_replace('/[^A-Za-z2-7]/', '', $code));
}

/* ---------- per-user state (table users + user_recovery_codes) ---------- */

/** Role-based policy (Administration › Paramètres › Sécurité). */
function two_fa_required(string $role): bool
{
    return match (setting('security.2fa_required')) {
        'all' => true,
        'admin' => role_rank($role) >= role_rank('admin'),
        default => false,
    };
}

function two_fa_state(int $userId): array
{
    $s = db()->prepare('SELECT totp_enabled_at, (SELECT COUNT(*) FROM user_recovery_codes c WHERE c.user_id = u.id AND c.used_at IS NULL) AS codes_left
                        FROM users u WHERE id = ?');
    $s->execute([$userId]);
    $r = $s->fetch() ?: [];
    return ['enabled' => !empty($r['totp_enabled_at']), 'since' => isset($r['totp_enabled_at']) ? (int) $r['totp_enabled_at'] : null,
        'codes_left' => (int) ($r['codes_left'] ?? 0)];
}

/** Checks a TOTP code or a recovery code for this account; consumes it. @return string|null 'totp' | 'recovery' */
function two_fa_check(int $userId, string $input): ?string
{
    $s = db()->prepare('SELECT totp_secret, totp_last_step FROM users WHERE id = ? AND totp_enabled_at IS NOT NULL');
    $s->execute([$userId]);
    $u = $s->fetch();
    if (!$u) {
        return null;
    }
    $step = totp_verify(secret_decrypt((string) $u['totp_secret']), $input, (int) $u['totp_last_step']);
    if ($step !== null) {
        db()->prepare('UPDATE users SET totp_last_step = ? WHERE id = ?')->execute([$step, $userId]);
        return 'totp';
    }
    $code = recovery_normalize($input);
    if (strlen($code) !== 10) {
        return null;
    }
    $s = db()->prepare('SELECT id, code_hash FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL');
    $s->execute([$userId]);
    foreach ($s->fetchAll() as $r) {
        if (password_verify($code, $r['code_hash'])) {
            db()->prepare('UPDATE user_recovery_codes SET used_at = ? WHERE id = ?')->execute([time(), $r['id']]);
            return 'recovery';
        }
    }
    return null;
}

/** Replaces the recovery codes. @return array the new codes, in clear (shown once) */
function two_fa_new_recovery_codes(int $userId): array
{
    $codes = recovery_codes_new();
    db()->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
    $ins = db()->prepare('INSERT INTO user_recovery_codes (user_id, code_hash, created_at) VALUES (?, ?, ?)');
    foreach ($codes as $c) {
        $ins->execute([$userId, password_hash(recovery_normalize($c), PASSWORD_DEFAULT), time()]);
    }
    return $codes;
}

/** Turns 2FA on once the user proved the app works ($step from totp_verify). @return array recovery codes */
function two_fa_enable(int $userId, string $secretB32, int $step): array
{
    db()->prepare('UPDATE users SET totp_secret = ?, totp_enabled_at = ?, totp_last_step = ? WHERE id = ?')
        ->execute([secret_encrypt($secretB32), time(), $step, $userId]);
    return two_fa_new_recovery_codes($userId);
}

function two_fa_disable(int $userId): void
{
    db()->prepare('UPDATE users SET totp_secret = NULL, totp_enabled_at = NULL, totp_last_step = 0 WHERE id = ?')->execute([$userId]);
    db()->prepare('DELETE FROM user_recovery_codes WHERE user_id = ?')->execute([$userId]);
}

/* ---------- QR code (byte mode, error correction M, versions 1 to 10), drawn as SVG ---------- */

/** version => [data codewords per block (one entry per block), EC codewords per block, alignment centres] */
const QR_M = [
    1 => [[16], 10, []], 2 => [[28], 16, [6, 18]], 3 => [[44], 26, [6, 22]], 4 => [[32, 32], 18, [6, 26]],
    5 => [[43, 43], 24, [6, 30]], 6 => [[27, 27, 27, 27], 16, [6, 34]], 7 => [[31, 31, 31, 31], 18, [6, 22, 38]],
    8 => [[38, 38, 39, 39], 22, [6, 24, 42]], 9 => [[36, 36, 36, 37, 37], 22, [6, 26, 46]],
    10 => [[43, 43, 43, 43, 44], 26, [6, 28, 50]],
];

function qr_gf(): array
{
    static $t = null;
    if ($t === null) {
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        $t = [$exp, $log];
    }
    return $t;
}

function qr_rs(array $data, int $n): array
{
    [$exp, $log] = qr_gf();
    $gen = [1];
    for ($i = 0; $i < $n; $i++) {   // (x - a^0)(x - a^1)…
        $next = array_fill(0, count($gen) + 1, 0);
        foreach ($gen as $j => $g) {
            $next[$j] ^= $g;
            $next[$j + 1] ^= $g ? $exp[$log[$g] + $i] : 0;
        }
        $gen = $next;
    }
    $rem = array_fill(0, $n, 0);
    foreach ($data as $d) {
        $f = $d ^ array_shift($rem);
        $rem[] = 0;
        if ($f) {
            for ($j = 0; $j < $n; $j++) {
                $rem[$j] ^= $gen[$j + 1] ? $exp[$log[$gen[$j + 1]] + $log[$f]] : 0;
            }
        }
    }
    return $rem;
}

function qr_bch(int $value, int $poly, int $bits): int
{
    $deg = strlen(decbin($poly)) - 1;
    $v = $value << $deg;
    while (strlen(decbin($v)) - 1 >= $deg && $v) {
        $v ^= $poly << (strlen(decbin($v)) - 1 - $deg);
    }
    return ($value << $deg) | $v;
}

/** @return array<int, array<int, bool>> modules (true = dark) */
function qr_matrix(string $text): array
{
    $len = strlen($text);
    $version = null;
    foreach (QR_M as $v => [$blocks]) {
        if (4 + ($v < 10 ? 8 : 16) + 8 * $len <= 8 * array_sum($blocks)) {
            $version = $v;
            break;
        }
    }
    if ($version === null) {
        throw new InvalidArgumentException('texte trop long pour le QR code');
    }
    [$blocks, $ecLen, $align] = QR_M[$version];
    $capacity = array_sum($blocks);
    // data bits: mode "byte", length, bytes, terminator, padding
    $bits = '0100' . str_pad(decbin($len), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
    foreach (str_split($text) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $bits .= str_repeat('0', min(4, 8 * $capacity - strlen($bits)));
    $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);
    $data = array_map('bindec', str_split($bits, 8));
    for ($i = 0; count($data) < $capacity; $i++) {
        $data[] = $i % 2 ? 0x11 : 0xEC;
    }
    // split in blocks, error correction, interleave
    $dataBlocks = [];
    $ecBlocks = [];
    $pos = 0;
    foreach ($blocks as $n) {
        $b = array_slice($data, $pos, $n);
        $pos += $n;
        $dataBlocks[] = $b;
        $ecBlocks[] = qr_rs($b, $ecLen);
    }
    $final = [];
    for ($i = 0; $i < max($blocks); $i++) {
        foreach ($dataBlocks as $b) {
            if (isset($b[$i])) {
                $final[] = $b[$i];
            }
        }
    }
    for ($i = 0; $i < $ecLen; $i++) {
        foreach ($ecBlocks as $b) {
            $final[] = $b[$i];
        }
    }
    $stream = '';
    foreach ($final as $byte) {
        $stream .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
    }
    $stream .= str_repeat('0', $version >= 2 && $version <= 6 ? 7 : 0);   // remainder bits

    $size = 17 + 4 * $version;
    $m = array_fill(0, $size, array_fill(0, $size, false));
    $fn = array_fill(0, $size, array_fill(0, $size, false));   // function patterns (not masked)
    $set = function (int $r, int $c, bool $dark) use (&$m, &$fn): void {
        $m[$r][$c] = $dark;
        $fn[$r][$c] = true;
    };
    foreach ([[0, 0], [0, $size - 7], [$size - 7, 0]] as [$r0, $c0]) {   // finders + separators
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $r0 + $r;
                $cc = $c0 + $c;
                if ($rr < 0 || $cc < 0 || $rr >= $size || $cc >= $size) {
                    continue;
                }
                $in = $r >= 0 && $r <= 6 && $c >= 0 && $c <= 6;
                $set($rr, $cc, $in && ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4)));
            }
        }
    }
    for ($i = 8; $i < $size - 8; $i++) {   // timing
        $set(6, $i, $i % 2 === 0);
        $set($i, 6, $i % 2 === 0);
    }
    $last = count($align) - 1;
    foreach ($align as $ai => $ar) {
        foreach ($align as $aj => $ac) {
            if (($ai === 0 && $aj === 0) || ($ai === 0 && $aj === $last) || ($ai === $last && $aj === 0)) {
                continue;   // the three corners hold the finders
            }
            for ($r = -2; $r <= 2; $r++) {
                for ($c = -2; $c <= 2; $c++) {
                    $set($ar + $r, $ac + $c, max(abs($r), abs($c)) !== 1);
                }
            }
        }
    }
    // reserve format (and version) areas
    for ($i = 0; $i < 9; $i++) {
        if (!$fn[8][$i]) $set(8, $i, false);
        if (!$fn[$i][8]) $set($i, 8, false);
    }
    for ($i = 0; $i < 8; $i++) {
        $set(8, $size - 1 - $i, false);
        $set($size - 1 - $i, 8, false);
    }
    $set($size - 8, 8, true);
    if ($version >= 7) {
        $vbits = qr_bch($version, 0x1F25, 12);
        for ($i = 0; $i < 18; $i++) {
            $dark = (bool) (($vbits >> $i) & 1);
            $set(intdiv($i, 3), $size - 11 + $i % 3, $dark);
            $set($size - 11 + $i % 3, intdiv($i, 3), $dark);
        }
    }
    // data, in the zigzag order
    $k = 0;
    $n = strlen($stream);
    for ($right = $size - 1, $up = true; $right >= 1; $right -= 2, $up = !$up) {
        if ($right === 6) {
            $right = 5;
        }
        for ($i = 0; $i < $size; $i++) {
            $r = $up ? $size - 1 - $i : $i;
            foreach ([$right, $right - 1] as $c) {
                if (!$fn[$r][$c]) {
                    $m[$r][$c] = $k < $n && $stream[$k] === '1';
                    $k++;
                }
            }
        }
    }
    // the mask with the lowest penalty wins
    $best = null;
    for ($mask = 0; $mask < 8; $mask++) {
        $t = qr_apply_mask($m, $fn, $mask, $size);
        $fmt = qr_bch((0b00 << 3) | $mask, 0x537, 10) ^ 0x5412;   // level M = 00
        for ($i = 0; $i < 15; $i++) {
            $dark = (bool) (($fmt >> $i) & 1);
            // first copy, around the top-left finder (skipping the timing lines)
            [$r, $c] = match (true) {
                $i <= 5 => [$i, 8],
                $i === 6 => [7, 8],
                $i === 7 => [8, 8],
                $i === 8 => [8, 7],
                default => [8, 14 - $i],
            };
            $t[$r][$c] = $dark;
            // second copy, split between the two other finders
            if ($i < 8) {
                $t[8][$size - 1 - $i] = $dark;
            } else {
                $t[$size - 15 + $i][8] = $dark;
            }
        }
        $t[$size - 8][8] = true;
        $score = qr_penalty($t, $size);
        if ($best === null || $score < $best[0]) {
            $best = [$score, $t];
        }
    }
    return $best[1];
}

function qr_apply_mask(array $m, array $fn, int $mask, int $size): array
{
    for ($r = 0; $r < $size; $r++) {
        for ($c = 0; $c < $size; $c++) {
            if ($fn[$r][$c]) {
                continue;
            }
            $flip = match ($mask) {
                0 => ($r + $c) % 2 === 0,
                1 => $r % 2 === 0,
                2 => $c % 3 === 0,
                3 => ($r + $c) % 3 === 0,
                4 => (intdiv($r, 2) + intdiv($c, 3)) % 2 === 0,
                5 => ($r * $c) % 2 + ($r * $c) % 3 === 0,
                6 => (($r * $c) % 2 + ($r * $c) % 3) % 2 === 0,
                7 => (($r + $c) % 2 + ($r * $c) % 3) % 2 === 0,
            };
            if ($flip) {
                $m[$r][$c] = !$m[$r][$c];
            }
        }
    }
    return $m;
}

function qr_penalty(array $m, int $size): int
{
    $p = 0;
    $dark = 0;
    $lines = [];
    for ($i = 0; $i < $size; $i++) {
        $row = $col = '';
        for ($j = 0; $j < $size; $j++) {
            $row .= $m[$i][$j] ? '1' : '0';
            $col .= $m[$j][$i] ? '1' : '0';
            $dark += $m[$i][$j] ? 1 : 0;
        }
        $lines[] = $row;
        $lines[] = $col;
    }
    foreach ($lines as $l) {
        preg_match_all('/0{5,}|1{5,}/', $l, $runs);
        foreach ($runs[0] as $run) {
            $p += strlen($run) - 2;
        }
        $p += 40 * (preg_match_all('/(?=10111010000|00001011101)/', $l));
    }
    for ($r = 0; $r < $size - 1; $r++) {
        for ($c = 0; $c < $size - 1; $c++) {
            $v = $m[$r][$c];
            if ($m[$r][$c + 1] === $v && $m[$r + 1][$c] === $v && $m[$r + 1][$c + 1] === $v) {
                $p += 3;
            }
        }
    }
    $p += 10 * intdiv(abs($dark * 20 - $size * $size * 10), $size * $size);
    return $p;
}

/** SVG of a QR code (quiet zone included), black on white whatever the theme, for the authenticator apps. */
function qr_svg(string $text, int $px = 220): string
{
    $m = qr_matrix($text);
    $size = count($m);
    $q = 4;
    $d = '';
    foreach ($m as $r => $row) {
        foreach ($row as $c => $dark) {
            if ($dark) {
                $d .= 'M' . ($c + $q) . ' ' . ($r + $q) . 'h1v1h-1z';
            }
        }
    }
    $total = $size + 2 * $q;
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" width="' . $px . '" height="' . $px
        . '" shape-rendering="crispEdges" role="img" aria-label="QR code à scanner avec l\'application d\'authentification">'
        . '<rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $d . '"/></svg>';
}
