<?php
declare(strict_types=1);

/** Readers of /proc, shared by the web page (live view) and the collector. */

/** @return array{0:int,1:int} [total jiffies, idle jiffies] */
function cpu_sample(): array
{
    $line = strtok((string) file_get_contents('/proc/stat'), "\n");
    $v = array_map('intval', array_slice(preg_split('/\s+/', trim((string) $line)), 1));
    return [array_sum($v), ($v[3] ?? 0) + ($v[4] ?? 0)];
}

function cpu_percent(array $a, array $b): float
{
    $dt = $b[0] - $a[0];
    return $dt > 0 ? round(100 * (1 - ($b[1] - $a[1]) / $dt), 1) : 0.0;
}

/** Bytes of /proc/meminfo keys. */
function meminfo(): array
{
    $m = [];
    foreach (file('/proc/meminfo') as $l) {
        if (preg_match('/^(\w+):\s+(\d+)/', $l, $x)) {
            $m[$x[1]] = (int) $x[2] * 1024;
        }
    }
    return $m;
}

function loadavg(): array
{
    $p = explode(' ', trim((string) file_get_contents('/proc/loadavg')));
    [$run, $all] = explode('/', $p[3] ?? '0/0');
    return ['load1' => (float) $p[0], 'load5' => (float) $p[1], 'load15' => (float) $p[2], 'procs' => (int) $all];
}

/** @return array{0:int,1:int} total rx / tx bytes, loopback excluded */
function net_bytes(): array
{
    $rx = $tx = 0;
    foreach (array_slice(file('/proc/net/dev'), 2) as $l) {
        [$if, $data] = array_map('trim', explode(':', $l, 2));
        if ($if === 'lo') {
            continue;
        }
        $f = preg_split('/\s+/', $data);
        $rx += (int) $f[0];
        $tx += (int) $f[8];
    }
    return [$rx, $tx];
}

function uptime_seconds(): int
{
    return (int) explode(' ', (string) file_get_contents('/proc/uptime'))[0];
}

/** Half-second sample, for the live view. */
function live(): array
{
    $c1 = cpu_sample();
    $n1 = net_bytes();
    usleep(500000);
    $c2 = cpu_sample();
    $n2 = net_bytes();
    $m = meminfo();
    return [
        'ts' => time(),
        'cpu' => cpu_percent($c1, $c2),
        'cores' => (int) preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo')),
        'mem_total' => $m['MemTotal'] ?? 0,
        'mem_avail' => $m['MemAvailable'] ?? 0,
        'swap_total' => $m['SwapTotal'] ?? 0,
        'swap_used' => ($m['SwapTotal'] ?? 0) - ($m['SwapFree'] ?? 0),
        'net_rx' => ($n2[0] - $n1[0]) * 2,
        'net_tx' => ($n2[1] - $n1[1]) * 2,
        'uptime' => uptime_seconds(),
    ] + loadavg();
}
