<?php
/**
 * SvxLink Dashboard — include/hardware_live.php
 * Endpoint JSON LÉGER pour le polling à 1 seconde.
 * Ne retourne QUE les valeurs dynamiques :
 *   cpu_snapshot, cpu_temp, ram, disk
 *
 * Pas de shell_exec, pas de usleep.
 * Temps de réponse cible : < 10 ms.
 */

if (ob_get_level()) ob_end_clean();
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/hardware_info.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');
header('X-Content-Type-Options: nosniff');

function live_cpuSnapshot(): array {
    if (!is_readable('/proc/stat')) return ['t' => 0, 'i' => 0];
    $c = @file_get_contents('/proc/stat');
    if ($c === false) return ['t' => 0, 'i' => 0];
    $p = preg_split('/\s+/', trim(strtok($c, "\n")));
    $v = [];
    for ($i = 1; $i < count($p); $i++) $v[] = (int)$p[$i];
    return [
        't' => array_sum($v),              
        'i' => isset($v[3]) ? $v[3] : 0,  
    ];
}

function live_cpuTemp(): string {
    $p = '/sys/class/thermal/thermal_zone0/temp';
    if (is_readable($p)) {
        $r = @file_get_contents($p);
        if ($r !== false) {
            $v = (int)trim($r);
            if ($v > 0) return number_format(($v / 1000.0) + CPU_TEMP_OFFSET, 1);
        }
    }
    return '';
}

function live_memInfo(): array {
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
        'total'   => live_fmt((float)($tot   * 1024)),
        'used'    => live_fmt((float)($used  * 1024)),
        'free'    => live_fmt((float)($avail * 1024)),
        'percent' => (int)round(($used / $tot) * 100),
    ];
}

function live_diskInfo(): array {
    $tot  = @disk_total_space('/');
    $free = @disk_free_space('/');
    if ($tot === false || $free === false || $tot <= 0) return [];
    $used = (float)$tot - (float)$free;
    return [
        'total'   => live_fmt((float)$tot),
        'used'    => live_fmt($used),
        'free'    => live_fmt((float)$free),
        'percent' => (int)round(($used / $tot) * 100),
    ];
}

function live_fmt(float $b): string {
    if ($b >= 1073741824.0) return number_format($b / 1073741824.0, 1) . ' GB';
    if ($b >= 1048576.0)    return number_format($b / 1048576.0, 0)   . ' MB';
    return number_format($b / 1024.0, 0) . ' KB';
}

echo json_encode([
    'cpu_snapshot'      => live_cpuSnapshot(),
    'cpu_temp'          => live_cpuTemp(),
    'cpu_temp_display'  => formatTempDisplay(live_cpuTemp()),
    'ram'               => live_memInfo(),
    'disk'              => live_diskInfo(),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;