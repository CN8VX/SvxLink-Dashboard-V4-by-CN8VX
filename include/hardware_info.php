<?php
/**
 * hardware_info entièrement conçue et développée par CN8VX © 2026.
 * Infos système : CPU, RAM, Disk, réseau, kernel, versions.
 * Compatible PHP 7.4+ (Debian 11/12/13), Raspberry Pi OS.
 *
 * NE PAS redéfinir les fonctions déjà dans functions.php.
 * Toutes les fonctions ici ont le préfixe hw_ pour éviter
 * tout conflit, sauf getAllHardwareInfo() point d'entrée.
 *
 * NOTE : format_uptime() est définie dans functions.php.
 *        Ce fichier doit toujours être inclus APRÈS functions.php.
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/config.php';

/* ── helper shell ────────────────────────────────────────────── */
function hw_cmd(string $cmd): string {
    $out = @shell_exec('LC_ALL=C ' . $cmd . ' 2>/dev/null');
    return ($out !== null) ? trim($out) : '';
}

/* ── CPU usage ───────────────────────────────────────────────── */
function hw_cpuUsage(): float {
    if (!is_readable('/proc/stat')) return 0.0;

    $read = function (): array {
        $c = @file_get_contents('/proc/stat');
        if ($c === false) return ['t' => 0, 'i' => 0];
        $p = preg_split('/\s+/', trim(strtok($c, "\n")));
        $v = [];
        for ($i = 1; $i < count($p); $i++) $v[] = (int)$p[$i];
        return ['t' => array_sum($v), 'i' => isset($v[3]) ? $v[3] : 0];
    };

    $a = $read(); usleep(200000); $b = $read();
    $dt = $b['t'] - $a['t']; $di = $b['i'] - $a['i'];
    if ($dt <= 0) return 0.0;
    return round((1.0 - ($di / $dt)) * 100.0, 1);
}

/* ── Formatage de la température affichée ───────────────────────
   Convertit une température (déjà corrigée par CPU_TEMP_OFFSET, en
   Celsius) dans l'unité configurée (TEMP_UNIT), avec son symbole.
   L'offset est appliqué une seule fois, là où le capteur est lu —
   cette fonction ne fait QUE la conversion d'unité d'affichage. */
function formatTempDisplay(string $celsiusStr): string {
    if ($celsiusStr === '' || !is_numeric($celsiusStr)) return '';
    $c = (float) $celsiusStr;

    if (TEMP_UNIT === 'F') {
        $f = ($c * 9 / 5) + 32;
        return number_format($f, 1) . '°F';
    }
    return number_format($c, 1) . '°C';
}

/* ── CPU temp ────────────────────────────────────────────────── */
function hw_cpuTemp(): string {
    $p = '/sys/class/thermal/thermal_zone0/temp';
    if (is_readable($p)) {
        $r = @file_get_contents($p);
        if ($r !== false) {
            $v = (int)trim($r);
            if ($v > 0) return number_format(($v / 1000.0) + CPU_TEMP_OFFSET, 1);
        }
    }
    $o = hw_cmd('vcgencmd measure_temp');
    if ($o !== '' && preg_match('/temp=([\d.]+)/', $o, $m)) return number_format((float)$m[1] + CPU_TEMP_OFFSET, 1);
    $o2 = hw_cmd('sensors -u | grep "temp1_input" | head -1');
    if ($o2 !== '' && preg_match('/([\d.]+)/', $o2, $m)) return number_format((float)$m[1] + CPU_TEMP_OFFSET, 1);
    return '';
}

/* ── CPU arch / model ────────────────────────────────────────── */
function hw_cpuArch(): string {
    $o = hw_cmd('uname -m');
    return ($o !== '') ? $o : php_uname('m');
}

function hw_cpuModel(): string {
    if (!is_readable('/proc/cpuinfo')) return '';
    $c = @file_get_contents('/proc/cpuinfo');
    if ($c === false) return '';
    foreach (['Model name', 'model name', 'Hardware', 'Model'] as $k) {
        if (preg_match('/^' . preg_quote($k, '/') . '\s*:\s*(.+)$/m', $c, $m)) {
            $v = trim($m[1]);
            if ($v !== '') return $v;
        }
    }
    return '';
}

/* ── CPU cores ───────────────────────────────────────────────── */
function hw_cpuCores(): int {
    $cores = (int)shell_exec('nproc 2>/dev/null');
    if ($cores > 0) return $cores;

    if (is_readable('/proc/cpuinfo')) {
        $c = @file_get_contents('/proc/cpuinfo');
        if ($c !== false) {
            $cores = preg_match_all('/^processor\s*:/m', $c, $matches);
            if ($cores > 0) return $cores;
        }
    }

    $cores = (int)shell_exec('getconf _NPROCESSORS_ONLN 2>/dev/null');
    if ($cores > 0) return $cores;

    return 1;
}

/* ── RAM ─────────────────────────────────────────────────────── */
function hw_memInfo(): array {
    if (!is_readable('/proc/meminfo')) return [];
    $c = @file_get_contents('/proc/meminfo');
    if ($c === false) return [];
    $mem = [];
    foreach (explode("\n", $c) as $l) {
        if (preg_match('/^(\w+):\s+(\d+)\s+kB/', $l, $m)) $mem[$m[1]] = (int)$m[2];
    }
    if (!isset($mem['MemTotal']) || $mem['MemTotal'] <= 0) return [];
    $tot   = $mem['MemTotal'];
    $avail = isset($mem['MemAvailable']) ? $mem['MemAvailable']
           : (isset($mem['MemFree'])     ? $mem['MemFree'] : 0);
    $used  = max(0, $tot - $avail);
    return [
        'total'   => hw_fmt((float)($tot   * 1024)),
        'used'    => hw_fmt((float)($used  * 1024)),
        'free'    => hw_fmt((float)($avail * 1024)),
        'percent' => (int)round(($used / $tot) * 100),
    ];
}

/* ── Disk ────────────────────────────────────────────────────── */
function hw_diskInfo(): array {
    $tot  = @disk_total_space('/');
    $free = @disk_free_space('/');
    if ($tot !== false && $free !== false && $tot > 0) {
        $used = (float)$tot - (float)$free;
        return [
            'total'   => hw_fmt((float)$tot),
            'used'    => hw_fmt($used),
            'free'    => hw_fmt((float)$free),
            'percent' => (int)round(($used / $tot) * 100),
        ];
    }
    $o = hw_cmd('df -B1 / | tail -1');
    if ($o === '') return [];
    $p = preg_split('/\s+/', $o);
    if (count($p) < 5) return [];
    $tot  = (float)$p[1]; $used = (float)$p[2]; $free = (float)$p[3];
    return [
        'total'   => hw_fmt($tot),
        'used'    => hw_fmt($used),
        'free'    => hw_fmt($free),
        'percent' => (int)str_replace('%', '', $p[4]),
    ];
}

/* ── Hostname ────────────────────────────────────────────────── */
function hw_hostname(): string {
    $h = @gethostname();
    if ($h !== false && $h !== '') return $h;
    return hw_cmd('hostname');
}

/* ── IP locale ───────────────────────────────────────────────── */
function hw_localIP(): string {
    $o = hw_cmd('hostname -I');
    if ($o !== '') {
        foreach (explode(' ', $o) as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && strpos($ip, ':') === false
                && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ip;
            }
        }
    }
    $o2 = hw_cmd("ip route get 1.1.1.1 | grep -oP 'src \K\S+'");
    if ($o2 !== '' && filter_var($o2, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $o2;
    $o3 = hw_cmd("ip -4 addr show scope global | grep -oP '(?<=inet\\s)\\d+(\\.\\d+){3}' | head -1");
    if ($o3 !== '' && filter_var($o3, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return $o3;
    return 'N/A';
}

/* ── Kernel / OS / Versions ──────────────────────────────────── */
function hw_kernel(): string {
    $o = hw_cmd('uname -r');
    return ($o !== '') ? $o : php_uname('r');
}

function hw_linuxVersion(): string {
    if (is_readable('/etc/os-release')) {
        $c = @file_get_contents('/etc/os-release');
        if ($c !== false) {
            foreach (explode("\n", $c) as $l) {
                if (strpos($l, 'PRETTY_NAME=') === 0) {
                    return trim(trim(substr($l, 12)), '"');
                }
            }
        }
    }
    $o = hw_cmd('lsb_release -d -s');
    return ($o !== '') ? $o : 'Linux';
}

function hw_svxlinkVersion(): string {
    $o = hw_cmd('svxlink --version 2>&1');
    if ($o !== '' && preg_match('/\b(\d+\.\d+[\d.]*)\b/', $o, $m)) return $m[1];
    $o2 = hw_cmd("dpkg-query -W -f='\${Version}' svxlink-server");
    if ($o2 !== '' && strpos($o2, 'svxlink-server') === false) return $o2;
    $o3 = hw_cmd("apt-cache policy svxlink-server | grep 'Installed:' | awk '{print \$2}'");
    if ($o3 !== '' && $o3 !== '(none)') return $o3;
    return 'N/A';
}

/* ── Uptime système — utilise format_uptime() de functions.php ─ */
function hw_systemUptime(): string {
    if (is_readable('/proc/uptime')) {
        $c = @file_get_contents('/proc/uptime');
        if ($c !== false) {
            $s = (int)explode(' ', trim($c))[0];
            return format_uptime($s);
        }
    }
    
    $fallback = hw_cmd('uptime -p');
    if ($fallback !== '') {
        $fallback = preg_replace(
            ['/^up\s+/', '/\s*days?\s*/', '/\s*hours?\s*/', '/\s*minutes?\s*/', '/,\s*/'],
            ['', 'd ', 'h ', 'm', ' '],
            $fallback
        );
        return trim($fallback);
    }
    return 'N/A';
}

/* ── Formater les octets ─────────────────────────────────────── */
function hw_fmt(float $b): string {
    if ($b >= 1073741824.0) return number_format($b / 1073741824.0, 1) . ' GB';
    if ($b >= 1048576.0)    return number_format($b / 1048576.0, 0)   . ' MB';
    return number_format($b / 1024.0, 0) . ' KB';
}

/* ── Fonction principale ─────────────────────────────────────── */
function getAllHardwareInfo(): array {
    return [
        'cpu_usage'       => hw_cpuUsage(),
        'cpu_temp'        => hw_cpuTemp(),
        'cpu_temp_display'=> formatTempDisplay(hw_cpuTemp()),
        'cpu_arch'        => hw_cpuArch(),
        'cpu_model'       => hw_cpuModel(),
        'cpu_cores'       => hw_cpuCores(),
        'ram'             => hw_memInfo(),
        'disk'            => hw_diskInfo(),
        'hostname'        => hw_hostname(),
        'local_ip'        => hw_localIP(),
        'kernel'          => hw_kernel(),
        'linux_version'   => hw_linuxVersion(),
        'svxlink_version' => hw_svxlinkVersion(),
        'system_uptime'   => hw_systemUptime(),
    ];
}

/* ── Endpoint JSON pour AJAX ─────────────────────────────────────── */
if (isset($_GET['json'])) {

    if (ob_get_level()) ob_end_clean();

    error_reporting(0);
    ini_set('display_errors', 0);

    require_once __DIR__ . '/config.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    header('X-Content-Type-Options: nosniff');

    $data = getAllHardwareInfo();
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}