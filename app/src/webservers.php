<?php
declare(strict_types=1);

/**
 * Sites served by Apache and / or Nginx, in one common shape (collector, run as root):
 *   name, aliases[], ports[], https, docroot, cert (file), confs[] ("apache:file" / "nginx:file"), server (apache|nginx), sockets[] (PHP-FPM)
 * Apache: « apache2ctl -S » + the vhost block. Nginx: « nginx -T » parsed into a directive tree
 * (includes such as snippets/*.conf are followed, so roots and fastcgi_pass found there count too).
 */

function web_vhosts(): array
{
    $sites = [];
    foreach ([...nginx_vhosts(), ...apache_vhosts()] as $v) {
        $s = &$sites[$v['name']];
        if ($s === null) {
            $s = $v;
        } else {   // same name on both servers (e.g. Nginx in front of Apache): merge
            foreach (['aliases', 'ports', 'confs', 'sockets'] as $k) {
                $s[$k] = array_values(array_unique([...$s[$k], ...$v[$k]]));
            }
            $s['https'] = $s['https'] || $v['https'];
            $s['docroot'] ??= $v['docroot'];
            $s['cert'] ??= $v['cert'];
            if (!str_contains($s['server'], $v['server'])) {
                $s['server'] .= '+' . $v['server'];
            }
        }
        unset($s);
    }
    ksort($sites);
    return $sites;
}

/** Real host names only (no catch-all, IP, wildcard or regex). */
function web_real_name(string $n): bool
{
    return $n !== '' && $n !== '_' && $n !== 'localhost' && !preg_match('/^(\d+\.){3}\d+$|^\[|[*~^$()]|:/', $n) && str_contains($n, '.');
}

/* ---------- Apache ---------- */

function apache_vhosts(): array
{
    if (!is_executable('/usr/sbin/apache2ctl') || trim((string) shell_exec('systemctl is-active apache2 2>/dev/null')) !== 'active') {
        return [];
    }
    $sites = [];
    $last = null;
    foreach (explode("\n", (string) shell_exec('apache2ctl -S 2>/dev/null')) as $l) {
        // "port 80 namevhost x (file:line)" when a port has several vhosts, "*:8080  x (file:line)" when it has only one
        if (preg_match('/port (\d+) namevhost (\S+) \((.+?):(\d+)\)/', $l, $x) || preg_match('/^\S*:(\d+)\s+(\S+) \((.+?):(\d+)\)/', $l, $x)) {
            [, $port, $name, $file, $line] = $x;
            $last = null;
            if (!web_real_name($name)) {
                continue;
            }
            $b = apache_vhost_block($file, (int) $line);
            $sites[$name] ??= ['name' => $name, 'aliases' => [], 'ports' => [], 'https' => false, 'docroot' => null, 'cert' => null, 'confs' => [], 'server' => 'apache', 'sockets' => []];
            $s = &$sites[$name];
            $s['ports'][] = (int) $port;
            $s['https'] = $s['https'] || (int) $port === 443 || $b['cert'] !== null;
            $s['confs'][] = 'apache:' . basename((string) (realpath($file) ?: $file), '.conf');
            $s['docroot'] ??= $b['docroot'];
            $s['cert'] ??= $b['cert'];
            if ($b['socket']) {
                $s['sockets'][] = $b['socket'];
            }
            $last = $name;
            unset($s);
        } elseif ($last !== null && preg_match('/^\s+alias (\S+)/', $l, $x) && web_real_name($x[1])) {
            $sites[$last]['aliases'][] = $x[1];
        }
    }
    foreach ($sites as &$s) {
        foreach (['aliases', 'ports', 'confs', 'sockets'] as $k) {
            $s[$k] = array_values(array_unique($s[$k]));
        }
    }
    return array_values($sites);
}

function apache_vhost_block(string $file, int $line): array
{
    $l = @file($file) ?: [];
    $r = ['docroot' => null, 'cert' => null, 'socket' => null];
    for ($i = max(0, $line - 1), $n = count($l); $i < $n; $i++) {
        if (preg_match('#^\s*</VirtualHost#i', $l[$i])) {
            break;
        }
        if (preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)/i', $l[$i], $x)) {
            $r['docroot'] = $x[1];
        } elseif (preg_match('/^\s*SSLCertificateFile\s+"?([^"\s]+)/i', $l[$i], $x)) {
            $r['cert'] = $x[1];
        } elseif (preg_match('#proxy:unix:([^|"]+)#', $l[$i], $x)) {
            $r['socket'] = $x[1];
        } elseif (preg_match('/^\s*Include(?:Optional)?\s+"?([^"\s]+)/i', $l[$i], $x) && is_file($x[1])) {
            $inc = (string) @file_get_contents($x[1]);   // e.g. /etc/hostkeep/apache-app.conf
            if (preg_match('/^\s*DocumentRoot\s+"?([^"\s]+)/mi', $inc, $y)) {
                $r['docroot'] ??= $y[1];
            }
            if (preg_match('#proxy:unix:([^|"]+)#', $inc, $y)) {
                $r['socket'] ??= $y[1];
            }
        }
    }
    return $r;
}

/** Apache vhosts: enabled / available names (config file names without .conf). */
function apache_site_files(): array
{
    $en = array_map(fn($f) => basename((string) (realpath($f) ?: $f), '.conf'), glob('/etc/apache2/sites-enabled/*.conf') ?: []);
    $av = array_map(fn($f) => basename($f, '.conf'), glob('/etc/apache2/sites-available/*.conf') ?: []);
    return ['enabled' => $en, 'available' => $av];
}

/* ---------- Nginx ---------- */

/** Every file of the running configuration, as dumped by « nginx -T »: path => text. */
function nginx_dump(): array
{
    static $files = null;
    if ($files !== null) {
        return $files;
    }
    $files = [];
    if (!is_executable('/usr/sbin/nginx') || trim((string) shell_exec('systemctl is-active nginx 2>/dev/null')) !== 'active') {
        return $files;
    }
    $cur = null;
    foreach (explode("\n", (string) shell_exec('nginx -T 2>/dev/null')) as $l) {
        if (preg_match('/^# configuration file (.+):$/', $l, $m)) {
            $cur = $m[1];
            $files[$cur] = '';
        } elseif ($cur !== null) {
            $files[$cur] .= $l . "\n";
        }
    }
    return $files;
}

/** Directive tree of an Nginx config text: list of [name, args[], children[]|null]. */
function nginx_parse(string $text): array
{
    $tokens = [];
    $len = strlen($text);
    $buf = '';
    for ($i = 0; $i < $len; $i++) {
        $c = $text[$i];
        if ($c === '#') {
            while ($i < $len && $text[$i] !== "\n") {
                $i++;
            }
            continue;
        }
        if ($c === '"' || $c === "'") {
            $j = $i + 1;
            while ($j < $len && $text[$j] !== $c) {
                $j += $text[$j] === '\\' ? 2 : 1;
            }
            $buf .= substr($text, $i + 1, $j - $i - 1);
            $i = $j;
            continue;
        }
        if (ctype_space($c) || $c === ';' || $c === '{' || $c === '}') {
            if ($buf !== '') {
                $tokens[] = $buf;
                $buf = '';
            }
            if (!ctype_space($c)) {
                $tokens[] = [$c];   // punctuation, kept apart from words
            }
            continue;
        }
        $buf .= $c;
    }
    $pos = 0;
    return nginx_block($tokens, $pos);
}

function nginx_block(array $t, int &$pos): array
{
    $out = [];
    $words = [];
    for ($n = count($t); $pos < $n; $pos++) {
        $tok = $t[$pos];
        if (is_array($tok)) {
            if ($tok[0] === ';') {
                if ($words) {
                    $out[] = [array_shift($words), $words, null];
                }
                $words = [];
            } elseif ($tok[0] === '{') {
                $pos++;
                $children = nginx_block($t, $pos);
                $out[] = [array_shift($words) ?? '', $words, $children];
                $words = [];
            } else {   // }
                return $out;
            }
        } else {
            $words[] = $tok;
        }
    }
    return $out;
}

/** Directives of a block, with « include » replaced by the included files (relative to /etc/nginx). */
function nginx_expand(array $nodes, array $files, int $depth = 0): array
{
    $out = [];
    foreach ($nodes as $n) {
        if ($n[0] === 'include' && $n[2] === null && $depth < 6) {
            $pattern = $n[1][0] ?? '';
            if ($pattern !== '' && $pattern[0] !== '/') {
                $pattern = '/etc/nginx/' . $pattern;
            }
            foreach ($files as $path => $txt) {
                if (fnmatch($pattern, $path)) {
                    array_push($out, ...nginx_expand(nginx_parse($txt), $files, $depth + 1));
                }
            }
        } else {
            $out[] = $n;
        }
    }
    return $out;
}

/** Every server { } block of the http context: [file, directives (includes expanded, nested locations kept)]. */
function nginx_servers(): array
{
    $files = nginx_dump();
    $servers = [];
    foreach ($files as $path => $txt) {
        $walk = function (array $nodes) use (&$walk, &$servers, $path, $files): void {
            foreach ($nodes as $n) {
                if ($n[2] === null) {
                    continue;
                }
                if ($n[0] === 'server') {
                    $servers[] = [$path, nginx_expand($n[2], $files)];
                } elseif (!in_array($n[0], ['stream', 'mail', 'location', 'upstream', 'map', 'if', 'types', 'events'], true)) {
                    $walk($n[2]);   // http { … }
                }
            }
        };
        $walk(nginx_parse($txt));
    }
    return $servers;
}

/** Collects a directive anywhere in a server block (locations and nested blocks included). */
function nginx_find(array $nodes, string $name): array
{
    $found = [];
    foreach ($nodes as $n) {
        if ($n[0] === $name && $n[2] === null) {
            $found[] = $n[1];
        }
        if ($n[2] !== null) {
            array_push($found, ...nginx_find($n[2], $name));
        }
    }
    return $found;
}

/** Top-level directive of a server block (not inside a location). */
function nginx_top(array $nodes, string $name): array
{
    return array_values(array_map(fn($n) => $n[1], array_filter($nodes, fn($n) => $n[0] === $name && $n[2] === null)));
}

function nginx_vhosts(): array
{
    $sites = [];
    foreach (nginx_servers() as [$file, $d]) {
        $names = array_values(array_filter(array_merge([], ...nginx_top($d, 'server_name')), 'web_real_name'));
        if (!$names) {
            continue;
        }
        $ports = [];
        $ssl = false;
        foreach (nginx_top($d, 'listen') as $a) {
            $addr = $a[0] ?? '80';
            $ports[] = (int) (preg_match('/(\d+)$/', $addr, $m) ? $m[1] : 80);
            $ssl = $ssl || in_array('ssl', $a, true) || str_ends_with($addr, '443');
        }
        $root = nginx_top($d, 'root')[0][0] ?? (nginx_find($d, 'root')[0][0] ?? null);
        $cert = nginx_top($d, 'ssl_certificate')[0][0] ?? null;
        $sockets = [];
        foreach (nginx_find($d, 'fastcgi_pass') as $a) {
            if (str_starts_with($a[0] ?? '', 'unix:')) {
                $sockets[] = substr($a[0], 5);
            }
        }
        $proxy = (bool) nginx_find($d, 'proxy_pass');
        $redirectOnly = !$root && !$sockets && !$proxy && (bool) nginx_find($d, 'return');
        $main = $names[0];
        $conf = 'nginx:' . basename((string) (realpath($file) ?: $file));
        $sites[$main] ??= ['name' => $main, 'aliases' => [], 'ports' => [], 'https' => false, 'docroot' => null, 'cert' => null, 'confs' => [], 'server' => 'nginx', 'sockets' => []];
        $s = &$sites[$main];
        $s['aliases'] = array_merge($s['aliases'], array_slice($names, 1));
        $s['ports'] = array_merge($s['ports'], $ports);
        $s['https'] = $s['https'] || $ssl || $cert !== null;
        if (!$redirectOnly) {
            $s['docroot'] ??= $root ?? ($proxy ? 'proxy → ' . (nginx_find($d, 'proxy_pass')[0][0] ?? '?') : null);
        }
        $s['cert'] ??= $cert;
        $s['confs'][] = $conf;
        $s['sockets'] = array_merge($s['sockets'], $sockets);
        unset($s);
    }
    foreach ($sites as &$s) {
        foreach (['aliases', 'ports', 'confs', 'sockets'] as $k) {
            $s[$k] = array_values(array_unique($s[$k]));
        }
        $s['aliases'] = array_values(array_diff($s['aliases'], [$s['name']]));
    }
    return array_values($sites);
}

/** Nginx sites: enabled / available file names (sites-available + sites-enabled layout only). */
function nginx_site_files(): array
{
    if (!is_dir('/etc/nginx/sites-available') || !is_dir('/etc/nginx/sites-enabled')) {
        return ['enabled' => [], 'available' => []];
    }
    $en = array_map(fn($f) => basename((string) (realpath($f) ?: $f)), glob('/etc/nginx/sites-enabled/*') ?: []);
    $av = array_map('basename', array_filter(glob('/etc/nginx/sites-available/*') ?: [], 'is_file'));
    return ['enabled' => $en, 'available' => $av];
}

/** Access logs that carry the host name on each line (traffic statistics), for both servers. */
function traffic_logs(): array
{
    return array_values(array_filter(['/var/log/apache2/hostkeep-traffic.log', '/var/log/nginx/hostkeep-traffic.log'], 'is_file'));
}

/** Nginx sites whose requests do not reach the traffic log (they set their own access_log without it). */
function nginx_untracked_sites(): array
{
    $out = [];
    $global = false;
    foreach (nginx_dump() as $txt) {
        foreach (nginx_parse($txt) as $n) {
            if ($n[0] === 'http' && $n[2] !== null) {
                foreach ($n[2] as $c) {
                    $global = $global || ($c[0] === 'access_log' && str_contains($c[1][0] ?? '', 'hostkeep-traffic'));
                }
            }
        }
        // conf.d files are inside http { } already
        if (preg_match('/^\s*access_log\s+\S*hostkeep-traffic/m', $txt) && !preg_match('/^\s*server\s*\{/m', $txt)) {
            $global = true;
        }
    }
    $tracked = [];
    foreach (nginx_servers() as [, $d]) {
        $names = array_values(array_filter(nginx_top($d, 'server_name')[0] ?? [], 'web_real_name'));
        if (!$names) {
            continue;
        }
        if (!nginx_find($d, 'root') && !nginx_find($d, 'proxy_pass') && !nginx_find($d, 'fastcgi_pass')) {
            continue;   // redirect-only block (e.g. HTTP → HTTPS): its log does not matter
        }
        $logs = nginx_top($d, 'access_log');
        $ok = (bool) array_filter($logs, fn($a) => str_contains($a[0] ?? '', 'hostkeep-traffic')) || (!$logs && $global);
        $tracked[$names[0]] = ($tracked[$names[0]] ?? false) || $ok;   // one tracked block (e.g. the HTTPS one) is enough
    }
    $out = array_keys(array_filter($tracked, fn($t) => !$t));
    return array_values(array_unique($out));
}
