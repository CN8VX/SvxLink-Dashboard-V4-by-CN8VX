<?php
/**
* Nouvelle version du fichier functions.php pour SvxLink-Dashboard, 
* entièrement conçue et développée par CN8VX © 2026.
* 
* New version of the functions.php file for SvxLink-Dashboard,
* fully designed and developed by CN8VX © 2026.
*/

require_once __DIR__ . '/cache_helper.php';

// ============================================================
// Encodage UTF-8 pour la lecture des fichiers
// ============================================================
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
    mb_regex_encoding('UTF-8');
}

// ============================================================
// HELPER PARTAGÉ — Formatage durée en secondes → "3d 14h 22m"
// Utilisé par getSvxlinkUptime() et hw_systemUptime()
// ============================================================

function format_uptime(int $seconds): string {
    $parts = [];
    if (($d = intdiv($seconds, 86400)) > 0) $parts[] = "{$d}d";
    if (($h = intdiv($seconds % 86400, 3600)) > 0) $parts[] = "{$h}h";
    $parts[] = intdiv($seconds % 3600, 60) . "m";
    return implode(' ', $parts);
}

// ============================================================
// TAIL EN PHP PUR (remplace "tail -N" sans passer par shell_exec)
// Lit le fichier depuis la fin par blocs de 8192 octets, sans
// charger tout le fichier en mémoire.
// ============================================================
function tail_lines(string $path, int $maxLines): array {
    if ($maxLines <= 0 || !is_readable($path)) return [];

    $fh = @fopen($path, 'rb');
    if ($fh === false) return [];

    $chunkSize = 8192;
    $pos       = filesize($path);
    $buffer    = '';
    $lineCount = 0;

    while ($pos > 0 && $lineCount <= $maxLines) {
        $read = min($chunkSize, $pos);
        $pos -= $read;
        fseek($fh, $pos);
        $buffer    = fread($fh, $read) . $buffer;
        $lineCount = substr_count($buffer, "\n");
    }
    fclose($fh);

    $lines = explode("\n", $buffer);
    if ($lines !== [] && end($lines) === '') array_pop($lines);

    return array_slice($lines, -$maxLines);
}

function tail_grep(string $path, int $maxLines, string $pattern): array {
    return array_values(preg_grep($pattern, tail_lines($path, $maxLines)));
}

// ============================================================
// SVXLINK LOGS
// ============================================================

function getSVXLog(): array {
    return dashboard_cached('svx_log', 5, function () {
        $logLines = [];
        $paths = [SVXLINK_LOG, SVXLINK_LOG . ".1"];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $filtered = tail_grep($path, 10000, '/Talker start on|Talker stop on/');
                $logLines = array_merge($logLines, $filtered);
            }
        }
        return array_slice($logLines, -500);
    });
}

function getSVXStatusLog(): array {
    return dashboard_cached('svx_status_log', 5, function () {
        $logLines = [];
        $paths = [SVXLINK_LOG, SVXLINK_LOG . ".1"];
        foreach ($paths as $path) {
            if (file_exists($path)) {
                $filtered = tail_grep($path, 10000, '/EchoLink QSO|ransmitter|Selecting/');
                $logLines = array_merge($logLines, $filtered);
            }
        }
        return array_slice($logLines, -250);
    });
}

function getSVXRstatus(): string {
    return dashboard_cached('svx_r_status', 2, function () {
        $pattern = '/Authentication|Connection established|Heartbeat timeout|No route to host|Connection refused|Connection timed out|Locally ordered disconnect|Deactivating link|Activating link/';

        $paths = [SVXLINK_LOG, SVXLINK_LOG . ".1"];

        foreach ($paths as $path) {
            if (!file_exists($path)) continue;

            $matches = tail_grep($path, 10000, $pattern);
            $line    = end($matches) ?: '';

            if ($line !== '') {
                if (preg_match('/Authentication OK|Connection established|Activating link/', $line)) {
                    return 'Connected';
                }
                if (preg_match('/timeout|No route|refused|disconnect/', $line)) {
                    return 'Not connected';
                }
            }
        }

        return 'No status';
    });
}

// ============================================================
// REPEATER STATUS (TX/RX/LISTENING)
// ============================================================

function getRepeaterStatus(): array {
    return dashboard_cached('repeater_status', 1, function () {
        return _computeRepeaterStatus();
    });
}

function _computeRepeaterStatus(): array {
    $logPath = resolveLogPath();

    if (!is_readable($logPath)) {
        return [
            'status'      => 'listening',
            'description' => 'Listening - Log file not found'
        ];
    }

    $lines = tail_lines($logPath, 200);

    if (empty($lines)) {
        return ['status' => 'listening', 'description' => 'Listening - No log data'];
    }

    $status           = 'listening';
    $description      = 'Listening - No recent activity';
    $squelchOpen      = false;   
    $squelchWasClosed = false;   
    $isIdenting       = false;   

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (stripos($line, 'squelch is OPEN') !== false) {
            $squelchOpen      = true;
            $squelchWasClosed = false;
            $status           = 'rx';
            $description      = 'RX - Receiving local RF signal';
            continue;
        }

        if (stripos($line, 'squelch is CLOSED') !== false) {
            $squelchOpen      = false;
            $squelchWasClosed = true;
            $status           = 'listening';
            $description      = 'Waiting for activity';
            continue;
        }

        if (stripos($line, 'Sending short identification') !== false
         || stripos($line, 'Sending long identification')  !== false) {
            $isIdenting       = true;
            $squelchWasClosed = false;
            continue;
        }

        if (stripos($line, 'Talker start') !== false) {
            if ($squelchOpen) {
                // Squelch ouvert → radio locale → RX
                $status      = 'rx';
                $description = 'RX - Receiving local RF signal';
            } else {
                // Pas de squelch → réseau (EchoLink / Reflector) → TX
                $status      = 'tx';
                $description = 'TX - Retransmitting network audio';
            }
            $isIdenting       = false;
            $squelchWasClosed = false;
            continue;
        }

        if (stripos($line, 'Talker stop') !== false) {
            $status           = 'listening';
            $description      = 'Waiting for activity';
            $squelchWasClosed = false;
            continue;
        }

        if (stripos($line, 'Turning the transmitter ON') !== false) {
            if ($isIdenting || $squelchWasClosed) {
                continue;
            }
            $status      = 'tx';
            $description = 'TX - Transmitting';
            continue;
        }

        if (stripos($line, 'Turning the transmitter OFF') !== false) {
            $isIdenting       = false;
            $squelchWasClosed = false;
            $status           = 'listening';
            $description      = 'Waiting for activity';
            continue;
        }
    }

    return ['status' => $status, 'description' => $description];
}

// ============================================================
// ECHOLINK
// ============================================================

function getEchoLog(): array {
    return dashboard_cached('echo_log', 5, function () {
        $path = SVXLINK_LOG;
        if (!is_readable($path)) return [];

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) return [];

        $matches = array_values(preg_grep('/EchoLink QSO/', $lines));
        return array_slice($matches, -500);
    });
}


function getConnectedEcholink(array $echolog): array {
    $users = [];

    foreach ($echolog as $line) {

        if (strpos($line, "CONNECTED") !== false) {
            $parts = explode(" ", $line);
            $pos = array_search('QSO', $parts) - 2;
            if ($pos >= 0 && isset($parts[$pos])) {
                $call = rtrim($parts[$pos], ':');
                if (!in_array($call, $users)) $users[] = $call;
            }
        }

        if (strpos($line, "DISCONNECTED") !== false) {
            $parts = explode(" ", $line);
            $pos = array_search('QSO', $parts) - 2;
            if ($pos >= 0 && isset($parts[$pos])) {
                $call = rtrim($parts[$pos], ':');
                $key = array_search($call, $users);
                if ($key !== false) unset($users[$key]);
            }
        }
    }

    return array_values($users);
}

function getEchoLinkTX(): string {
    return dashboard_cached('echolink_tx', 5, function () {
        $path = SVXLINK_LOG;
        if (!file_exists($path)) return '';

        $matches = tail_grep($path, 10000, '/### EchoLink/');
        $line    = end($matches) ?: '';

        if ($line !== '' && strpos($line, "talker start") !== false) {
            return trim(substr($line, strpos($line, "start") + 6, 12));
        }

        return '';
    });
}

// ============================================================
// TG / REFLECTOR
// ============================================================

function getSVXTGSelect(): string {
    return dashboard_cached('svx_tg_select', 2, function () {
        $path = SVXLINK_LOG;
        if (!file_exists($path)) return '';

        $matches = tail_grep($path, 10000, '/Selecting/');
        $line    = end($matches) ?: '';

        if ($line !== '' && strpos($line, "TG #") !== false) {
            return trim(substr($line, strpos($line, "#") + 1, 12));
        }

        return '';
    });
}


function getSVXTGTMP(): string {
    return dashboard_cached('svx_tg_tmp', 2, function () {
        $path = SVXLINK_LOG;
        if (!file_exists($path)) return '';

        $matches = tail_grep($path, 10000, '/emporary monitor/');
        $line    = end($matches) ?: '';

        if ($line !== '' && strpos($line, "Add") !== false) {
            return trim(substr($line, strpos($line, "#") + 1, 12));
        }

        return '';
    });
}

// ============================================================
// UTILS
// ============================================================

function isProcessRunning(string $name): bool {
    return dashboard_cached('proc_running_' . $name, 5, function () use ($name) {
        return getPidByName($name) !== 0;
    });
}

// Cherche le PID d'un process par son nom exact (équivalent de
// `pgrep -x`), en lisant directement /proc — sans passer par un
// shell externe.
function getPidByName(string $name): int {
    $procs = @scandir('/proc');
    if ($procs === false) return 0;

    foreach ($procs as $pid) {
        if (!ctype_digit($pid)) continue;

        $commPath = "/proc/$pid/comm";
        if (!is_readable($commPath)) continue;

        if (trim((string)@file_get_contents($commPath)) === $name) {
            return (int)$pid;
        }
    }
    return 0;
}


function parse_svxlink_config(string $filePath): array {
    $config = [];
    if (!file_exists($filePath)) return $config;

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $section = '';

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || $line[0] === ';' || $line[0] === '#') continue;

        if ($line[0] === '[') {
            $section = trim($line, '[]');
            $config[$section] = [];
        } elseif (strpos($line, '=') !== false && $section !== '') {
            [$k, $v] = explode('=', $line, 2);
            $config[$section][trim($k)] = trim($v, ' "');
        }
    }

    return $config;
}

// ============================================================
// STATUT SVXLINK (systemctl)
// ============================================================

function getSvxlinkStatus(): string {
    return dashboard_cached('svx_status', 5, function () {
        $out = shell_exec('systemctl is-active svxlink 2>/dev/null');
        return trim($out !== null ? $out : 'unknown');
    });
}

function getSvxlinkUptime(): string {
    return dashboard_cached('svx_uptime', 5, function () {
        $out = shell_exec('systemctl show svxlink --property=ActiveEnterTimestamp 2>/dev/null');
        if (!$out) return '';
        if (!preg_match('/ActiveEnterTimestamp=(.+)/', trim($out), $m)) return '';
        if (trim($m[1]) === '') return '';
        $start = strtotime($m[1]);
        if (!$start || $start <= 0) return '';
        return format_uptime((int)(time() - $start));
    });
}

// ============================================================
// ENDPOINT JSON — Uptime SvxLink
// URL : include/functions.php?uptime_json=1
// ============================================================

if (isset($_GET['uptime_json'])) {
    if (ob_get_level()) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    if (!defined('SVXLINK_LOG')) require_once __DIR__ . '/config.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    header('X-Content-Type-Options: nosniff');

    $status = '';
    $uptime = '';

    try {
        $status = getSvxlinkStatus();
        $uptime = getSvxlinkUptime();
    } catch (Exception $e) {
        $status = 'unknown';
        $uptime = '';
    }

    echo json_encode([
        'status' => $status !== '' ? $status : 'unknown',
        'uptime' => $uptime,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// ============================================================
// ENDPOINT JSON — EchoLink live
// URL : include/functions.php?echolink_json=1
// ============================================================

if (isset($_GET['echolink_json'])) {
    if (ob_get_level()) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    if (!defined('SVXLINK_LOG')) require_once __DIR__ . '/config.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    header('X-Content-Type-Options: nosniff');

    $elCallsign  = '';
    $elSysopName = '';
    $elLocation  = '';
    $elUsers     = [];

    $echolinkConfPath = '/etc/svxlink/svxlink.d/ModuleEchoLink.conf';
    if (file_exists($echolinkConfPath)) {
        $elConfig    = parse_svxlink_config($echolinkConfPath);
        $elCallsign  = $elConfig['ModuleEchoLink']['CALLSIGN']  ?? '';
        $elSysopName = $elConfig['ModuleEchoLink']['SYSOPNAME'] ?? '';
        $elLocation  = $elConfig['ModuleEchoLink']['LOCATION']  ?? '';
    }

    if (isProcessRunning('svxlink')) {
        $log     = getEchoLog();
        $elUsers = getConnectedEcholink($log);
    }

    $elCallsignQrz = extractQrzCallsign($elCallsign);

    echo json_encode([
        'callsign'        => $elCallsign,
        'callsign_qrz'    => $elCallsignQrz,
        'sysop'           => $elSysopName,
        'location'        => $elLocation,
        'connected_nodes' => $elUsers,
        'connected_count' => count($elUsers),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}

// ============================================================
// REFLECTOR — HELPERS
// ============================================================

function resolveLogPath(): string {
    $logPath = SVXLINK_LOG;
    if (!is_dir($logPath)) return $logPath;

    foreach (['svxlink', 'svxlink.log'] as $candidate) {
        $full = $logPath . '/' . $candidate;
        if (is_readable($full)) return $full;
    }

    $files = glob($logPath . '/*');
    if ($files !== false) {
        foreach ($files as $file) {
            if (is_file($file) && is_readable($file)) return $file;
        }
    }

    return $logPath;
}

function stripCallsignSuffix(string $callsign): string {
    $pos = strpos($callsign, '-');
    return $pos !== false ? substr($callsign, 0, $pos) : $callsign;
}

// ============================================================
// QRZ — Extraction de l'indicatif "propre" pour la recherche QRZ.com
// Gère les formats bruts SVXReflector / EchoLink, ex :
//   "(67) F5TFB V"  -> "F5TFB"
//   "(67) F5TFB"    -> "F5TFB"
//   "F5TFB V"       -> "F5TFB"
//   "F5TFB-L"       -> "F5TFB"
// ============================================================
function extractQrzCallsign(string $raw): string {
    $s = trim($raw);
    if ($s === '') return '';

    // Retire un éventuel préfixe "(numéro) " (ID de nœud SVXReflector)
    $s = preg_replace('/^\(\d+\)\s*/', '', $s);

    // Ne garde que le premier "mot" : un suffixe après un espace
    // (type de client SVXReflector "V", "M"...) n'est pas l'indicatif.
    $parts = preg_split('/\s+/', trim($s));
    $s = $parts[0] ?? '';

    // Retire un éventuel suffixe technique après un tiret (EchoLink -L/-R...)
    return stripCallsignSuffix($s);
}

function isGatewayNode(string $name): bool {
    return preg_match('/^(GW[_-]|XLX[_-]|XRF[_-]|REF[_-]|SYSOP[_-])/i', $name) === 1;
}

function isQrzCandidate(string $name): bool {
    return $name !== '' && !isGatewayNode($name);
}

function formatDuration(int $sec): string {
    if ($sec <= 0) return '—';
    $m = (int)floor($sec / 60);
    $s = $sec % 60;
    return sprintf('%02d:%02d', $m, $s);
}


function getTGName(string $tg): string {
    $tg = trim($tg);

    // Plage réservée "Auto QSY" du reflector : redirection automatique
    // et temporaire vers un TG technique, pas une vraie communauté
    // nommée. On évite d'avoir à lister 100 entrées bidon dans
    // talkgroups.json rien que pour couvrir cette plage.
    if (ctype_digit($tg)) {
        $tgNumber = (int) $tg;
        if ($tgNumber >= 60499900 && $tgNumber <= 60499999) {
            return '🔀 AUTO QSY';
        }
    }

    static $tgdb = null;
    if ($tgdb === null) {
        $path = __DIR__ . '/talkgroups.json';
        if (is_readable($path)) {
            $raw     = file_get_contents($path);
            $decoded = json_decode($raw, true);
            $tgdb    = (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) ? $decoded : [];
        } else {
            $tgdb = [];
        }
    }

    return $tgdb[$tg] ?? '';
}

// ============================================================
// REFLECTOR — PARSING TIMESTAMP
// ============================================================

function _monthToNum(string $name): ?int {
    static $map = null;
    if ($map === null) {
        $map = [
            // ── JANVIER / 1 ─────────────────────────────────────
            'jan'        => 1, 'january'    => 1,
            // FR
            'janv'       => 1, 'janvier'    => 1,
            // ES/PT
            'ene'        => 1, 'enero'      => 1,
            'jan'        => 1, 'janeiro'    => 1,
            // DE
            'jan'        => 1, 'januar'     => 1,
            // IT
            'gen'        => 1, 'gennaio'    => 1,
            // NL
            'jan'        => 1,
            // PL
            'sty'        => 1, 'styczen'    => 1, 'stycznia'   => 1,
            // RU translittéré
            'yanv'       => 1,
            // TR
            'oca'        => 1, 'ocak'       => 1,
            // SV/NO/DA
            'jan'        => 1,
            // FI
            'tam'        => 1, 'tammikuu'   => 1,
            // CS
            'led'        => 1, 'leden'      => 1,
            // RO
            'ian'        => 1, 'ianuarie'   => 1,
            // HU
            'jan'        => 1, 'januar'     => 1,
            // AR translittéré
            'yanayir'    => 1, 'yanayr'     => 1,

            // ── FÉVRIER / 2 ─────────────────────────────────────
            'feb'        => 2, 'february'   => 2,
            // FR
            'fev'        => 2, 'fevr'       => 2, 'févr'      => 2,
            'fév'        => 2, 'fevrier'    => 2, 'février'   => 2,
            // ES
            'feb'        => 2, 'febrero'    => 2,
            // PT
            'fev'        => 2, 'fevereiro'  => 2,
            // DE
            'feb'        => 2, 'februar'    => 2,
            // IT
            'feb'        => 2, 'febbraio'   => 2,
            // PL
            'lut'        => 2, 'luty'       => 2, 'lutego'    => 2,
            // RU translittéré
            'fevr'       => 2,
            // TR
            'sub'        => 2, 'subat'      => 2, 'şubat'     => 2,
            // FI
            'hel'        => 2, 'helmikuu'   => 2,
            // CS
            'uno'        => 2, 'unor'       => 2, 'února'     => 2,
            // RO
            'feb'        => 2, 'februarie'  => 2,

            // ── MARS / 3 ────────────────────────────────────────
            'mar'        => 3, 'march'      => 3,
            // FR
            'mars'       => 3,
            // ES/PT
            'mar'        => 3, 'marzo'      => 3, 'marco'     => 3, 'março'    => 3,
            // DE
            'mrz'        => 3, 'mär'        => 3, 'maerz'     => 3, 'märz'     => 3,
            // IT
            'mar'        => 3, 'marzo'      => 3,
            // NL
            'mrt'        => 3, 'maart'      => 3,
            // PL
            'mar'        => 3, 'marzec'     => 3, 'marca'     => 3,
            // RU translittéré
            'mart'       => 3,
            // TR
            'mar'        => 3, 'mart'       => 3,
            // SV/NO/DA
            'mar'        => 3, 'mars'       => 3,
            // FI
            'maa'        => 3, 'maali'      => 3, 'maaliskuu' => 3,
            // CS
            'brez'       => 3, 'brezen'     => 3, 'března'    => 3,
            // RO
            'mar'        => 3, 'martie'     => 3,
            // HU
            'mar'        => 3, 'marcius'    => 3, 'március'   => 3,

            // ── AVRIL / 4 ───────────────────────────────────────
            'apr'        => 4, 'april'      => 4,
            // FR
            'avr'        => 4, 'avril'      => 4,
            // ES
            'abr'        => 4, 'abril'      => 4,
            // PT
            'abr'        => 4, 'abril'      => 4,
            // DE
            'apr'        => 4,
            // IT
            'apr'        => 4, 'aprile'     => 4,
            // NL
            'apr'        => 4,
            // PL
            'kwi'        => 4, 'kwiecien'   => 4, 'kwietnia'  => 4,
            // RU translittéré
            'apr'        => 4, 'aprel'      => 4,
            // TR
            'nis'        => 4, 'nisan'      => 4,
            // FI
            'huh'        => 4, 'huhtikuu'   => 4,
            // CS
            'dub'        => 4, 'duben'      => 4, 'dubna'     => 4,
            // RO
            'apr'        => 4, 'aprilie'    => 4,

            // ── MAI / 5 ─────────────────────────────────────────
            'may'        => 5,
            // FR
            'mai'        => 5,
            // ES/PT
            'may'        => 5, 'mayo'       => 5, 'maio'      => 5,
            // DE
            'mai'        => 5,
            // IT
            'mag'        => 5, 'maggio'     => 5,
            // NL
            'mei'        => 5,
            // PL
            'maj'        => 5, 'maja'       => 5,
            // RU translittéré
            'may'        => 5, 'mai'        => 5,
            // TR
            'may'        => 5, 'mayis'      => 5, 'mayıs'     => 5,
            // SV/NO/DA
            'maj'        => 5,
            // FI
            'tou'        => 5, 'toukokuu'   => 5,
            // CS
            'kve'        => 5, 'kveten'     => 5, 'května'    => 5,
            // RO
            'mai'        => 5,

            // ── JUIN / 6 ────────────────────────────────────────
            'jun'        => 6, 'june'       => 6,
            // FR
            'juin'       => 6,
            // ES/PT
            'jun'        => 6, 'junio'      => 6, 'junho'     => 6,
            // DE
            'jun'        => 6, 'juni'       => 6,
            // IT
            'giu'        => 6, 'giugno'     => 6,
            // NL
            'jun'        => 6, 'juni'       => 6,
            // PL
            'cze'        => 6, 'czerwiec'   => 6, 'czerwca'   => 6,
            // RU translittéré
            'iyun'       => 6,
            // TR
            'haz'        => 6, 'haziran'    => 6,
            // SV/NO/DA
            'jun'        => 6, 'juni'       => 6,
            // FI
            'kes'        => 6, 'kesakuu'    => 6, 'kesäkuu'   => 6,
            // CS
            'cer'        => 6, 'cerven'     => 6, 'června'    => 6,
            // RO
            'iun'        => 6, 'iunie'      => 6,

            // ── JUILLET / 7 ─────────────────────────────────────
            'jul'        => 7, 'july'       => 7,
            // FR
            'juil'       => 7, 'jui'        => 7, 'juillet'   => 7,
            // ES/PT
            'jul'        => 7, 'julio'      => 7, 'julho'     => 7,
            // DE
            'jul'        => 7, 'juli'       => 7,
            // IT
            'lug'        => 7, 'luglio'     => 7,
            // NL
            'jul'        => 7, 'juli'       => 7,
            // PL
            'lip'        => 7, 'lipiec'     => 7, 'lipca'     => 7,
            // RU translittéré
            'iyul'       => 7,
            // TR
            'tem'        => 7, 'temmuz'     => 7,
            // SV/NO/DA
            'jul'        => 7, 'juli'       => 7,
            // FI
            'hei'        => 7, 'heinakuu'   => 7, 'heinäkuu'  => 7,
            // CS
            'cerv'       => 7, 'cervenec'   => 7, 'července'  => 7,
            // RO
            'iul'        => 7, 'iulie'      => 7,

            // ── AOÛT / 8 ────────────────────────────────────────
            'aug'        => 8, 'august'     => 8,
            // FR
            'aou'        => 8, 'aout'       => 8, 'août'      => 8, 'aoû'      => 8,
            // ES/PT
            'ago'        => 8, 'agosto'     => 8,
            // DE
            'aug'        => 8,
            // IT
            'ago'        => 8, 'agosto'     => 8,
            // NL
            'aug'        => 8,
            // PL
            'sie'        => 8, 'sierpien'   => 8, 'sierpnia'  => 8,
            // RU translittéré
            'avg'        => 8, 'avgust'     => 8,
            // TR
            'agu'        => 8, 'agustos'    => 8, 'ağustos'   => 8,
            // SV/NO/DA
            'aug'        => 8, 'aug'        => 8,
            // FI
            'elo'        => 8, 'elokuu'     => 8,
            // CS
            'srp'        => 8, 'srpen'      => 8, 'srpna'     => 8,
            // RO
            'aug'        => 8, 'august'     => 8,

            // ── SEPTEMBRE / 9 ───────────────────────────────────
            'sep'        => 9, 'september'  => 9,
            // FR
            'sept'       => 9, 'septembre'  => 9,
            // ES/PT
            'sep'        => 9, 'septiembre' => 9, 'setembro'  => 9,
            // DE
            'sep'        => 9, 'sept'       => 9,
            // IT
            'set'        => 9, 'settembre'  => 9,
            // NL
            'sep'        => 9,
            // PL
            'wrz'        => 9, 'wrzesien'   => 9, 'wrzesnia'  => 9,
            // RU translittéré
            'sent'       => 9, 'sentyabr'   => 9,
            // TR
            'eyl'        => 9, 'eylul'      => 9, 'eylül'     => 9,
            // SV/NO/DA
            'sep'        => 9,
            // FI
            'syy'        => 9, 'syyskuu'    => 9,
            // CS
            'zar'        => 9, 'zari'       => 9, 'září'      => 9,
            // RO
            'sep'        => 9, 'septembrie' => 9,

            // ── OCTOBRE / 10 ────────────────────────────────────
            'oct'        => 10, 'october'   => 10,
            // FR
            'oct'        => 10, 'octobre'   => 10,
            // ES/PT
            'oct'        => 10, 'octubre'   => 10, 'outubro'  => 10,
            // DE
            'okt'        => 10, 'oktober'   => 10,
            // IT
            'ott'        => 10, 'ottobre'   => 10,
            // NL
            'okt'        => 10, 'oktober'   => 10,
            // PL
            'paz'        => 10, 'pazdzier'  => 10, 'pazdziernika' => 10,
            // RU translittéré
            'okt'        => 10, 'oktyabr'   => 10,
            // TR
            'eki'        => 10, 'ekim'      => 10,
            // SV/NO/DA
            'okt'        => 10,
            // FI
            'lok'        => 10, 'lokakuu'   => 10,
            // CS
            'lis'        => 10, 'rijen'     => 10, 'října'    => 10,
            // RO
            'oct'        => 10, 'octombrie' => 10,

            // ── NOVEMBRE / 11 ───────────────────────────────────
            'nov'        => 11, 'november'  => 11,
            // FR
            'nov'        => 11, 'novembre'  => 11,
            // ES/PT
            'nov'        => 11, 'noviembre' => 11, 'novembro' => 11,
            // DE
            'nov'        => 11,
            // IT
            'nov'        => 11, 'novembre'  => 11,
            // NL
            'nov'        => 11,
            // PL
            'lis'        => 11, 'listopad'  => 11, 'listopada'=> 11,
            // RU translittéré
            'noy'        => 11, 'noyabr'    => 11,
            // TR
            'kas'        => 11, 'kasim'     => 11, 'kasım'    => 11,
            // FI
            'mar'        => 11, 'marraskuu' => 11,
            // CS
            'lis'        => 11, 'listopad'  => 11,
            // RO
            'nov'        => 11, 'noiembrie' => 11,

            // ── DÉCEMBRE / 12 ───────────────────────────────────
            'dec'        => 12, 'december'  => 12,
            // FR
            'dec'        => 12, 'déc'       => 12, 'decembre' => 12, 'décembre' => 12,
            // ES/PT
            'dic'        => 12, 'diciembre' => 12, 'dezembro' => 12, 'dez'      => 12,
            // DE
            'dez'        => 12, 'dezember'  => 12,
            // IT
            'dic'        => 12, 'dicembre'  => 12,
            // NL
            'dec'        => 12,
            // PL
            'gru'        => 12, 'grudzien'  => 12, 'grudnia'  => 12,
            // RU translittéré
            'dek'        => 12, 'dekabr'    => 12,
            // TR
            'ara'        => 12,
            // SV/NO/DA
            'dec'        => 12,
            // FI
            'jou'        => 12, 'joulukuu'  => 12,
            // CS
            'pro'        => 12, 'prosinec'  => 12,
            // RO
            'dec'        => 12, 'decembrie' => 12,
            // HU
            'dec'        => 12, 'december'  => 12,
        ];
    }

    $key = trim($name);
    $key = rtrim($key, '.');

    static $from = ['É','È','Ê','Ë','Â','Î','Ô','Û','Ù','À','Ä','Ö','Ü','Ç','Ñ','Ş','Ğ','İ',
                    'é','è','ê','ë','â','î','ô','û','ù','à','ä','ö','ü','ç','ñ','ş','ğ','ı',
                    'Á','Ó','Ú','Í','á','ó','ú','í',
                    'ą','ę','ś','ź','ż','ć','ń','ó','ł','Ą','Ę','Ś','Ź','Ż','Ć','Ń','Ó','Ł',
                    'ě','š','č','ř','ž','ý','ů','Ě','Š','Č','Ř','Ž','Ý','Ů'];
    static $to   = ['e','e','e','e','a','i','o','u','u','a','a','o','u','c','n','s','g','i',
                    'e','e','e','e','a','i','o','u','u','a','a','o','u','c','n','s','g','i',
                    'a','o','u','i','a','o','u','i',
                    'a','e','s','z','z','c','n','o','l','a','e','s','z','z','c','n','o','l',
                    'e','s','c','r','z','y','u','e','s','c','r','z','y','u'];
    $key = str_replace($from, $to, $key);
    $key = strtolower($key);

    return $map[$key] ?? null;
}

function extractLogTimestamp(string $line): ?array {

    $mkts = static function(int $h, int $i, int $s, int $mo, int $d, int $y): int {
        return (int)mktime($h, $i, $s, $mo, $d, $y);
    };
    $ret = static function(int $ts): array {
        return ['timestamp' => $ts, 'datetime' => date('d M Y H:i:s', $ts)];
    };
    $hms = static function(string $t): array {
        return [(int)substr($t,0,2),(int)substr($t,3,2),(int)substr($t,6,2)];
    };

    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}:\d{2}:\d{2})/', $line, $m)) {
        $ts = mktime((int)substr($m[4],0,2),(int)substr($m[4],3,2),(int)substr($m[4],6,2),
                     (int)$m[2],(int)$m[3],(int)$m[1]);
        if ($ts > 0) return $ret($ts);
    }

    if (preg_match(
        '/^\S+\s+(\S+)\s+(\d{1,2})\s+(\d{2}:\d{2}:\d{2})\s+(\d{4})\s*:?/u',
        $line, $m
    )) {
        $num = _monthToNum($m[1]);
        if ($num) {
            [$h,$i,$s] = $hms($m[3]);
            $ts = $mkts($h,$i,$s,$num,(int)$m[2],(int)$m[4]);
            if ($ts > 0) return $ret($ts);
        }
    }

    if (preg_match('/^(\S+)\s+(\d{1,2})\s+(\d{2}:\d{2}:\d{2})\s+(\d{4})\s*:?/u', $line, $m)) {
        $num = _monthToNum($m[1]);
        if ($num) {
            [$h,$i,$s] = $hms($m[3]);
            $ts = $mkts($h,$i,$s,$num,(int)$m[2],(int)$m[4]);
            if ($ts > 0) return $ret($ts);
        }
    }

    if (preg_match('/^(\S+)\s+(\d{1,2})\s+(\d{2}:\d{2}:\d{2})/u', $line, $m)) {
        $num = _monthToNum($m[1]);
        if ($num) {
            [$h,$i,$s] = $hms($m[3]);
            $ts = $mkts($h,$i,$s,$num,(int)$m[2],(int)date('Y'));
            if ($ts > 0) return $ret($ts);
        }
    }

    
    if (preg_match(
        '/^\S+\.?\s+(\d{1,2})\.?\s+(\S+?)\.?\s+(\d{4})\s+(\d{2}:\d{2}:\d{2})/u',
        $line, $m
    )) {
        $num = _monthToNum($m[2]);
        if ($num) {
            [$h,$i,$s] = $hms($m[4]);
            $ts = $mkts($h,$i,$s,$num,(int)$m[1],(int)$m[3]);
            if ($ts > 0) return $ret($ts);
        }
    }

    return null;
}

// ============================================================
// REFLECTOR — PARSING LIGNES LOG
// ============================================================

function parseReflectorLogLine(string $line): ?array {
    $isStart = stripos($line, 'Talker start') !== false;
    $isStop  = stripos($line, 'Talker stop')  !== false;
    if (!$isStart && !$isStop) return null;

    $timeInfo = extractLogTimestamp($line);
    if ($timeInfo === null) return null;

    $tg = '';
    if (preg_match('/TG\s*#?(\d+)/i', $line, $m)) $tg = $m[1];

    $callsign = '';
    if (preg_match('/TG\s*#?\d+\s*:\s*([A-Z0-9][A-Z0-9_-]+)\s*$/i', $line, $m)) {
        $callsign = strtoupper(trim($m[1]));
    }

    if ($tg === '' || $callsign === '') return null;

    return [
        'datetime'     => $timeInfo['datetime'],
        'timestamp'    => $timeInfo['timestamp'],
        'callsign'     => $callsign,
        'callsign_qrz' => extractQrzCallsign($callsign),
        'is_gateway'   => isGatewayNode($callsign),
        'tg'           => $tg,
        'type'         => $isStart ? 'start' : 'stop',
    ];
}

function parseConnectedNodesLine(string $line): ?array {
    if (strpos($line, 'Connected nodes:') === false) return null;
    $timeInfo = extractLogTimestamp($line);
    if ($timeInfo === null) return null;
    if (!preg_match('/Connected nodes:\s*(.+)$/', $line, $m)) return null;
    $nodes = array_filter(array_map('trim', explode(',', $m[1])));
    if (empty($nodes)) return null;
    return [
        'datetime'  => $timeInfo['datetime'],
        'timestamp' => $timeInfo['timestamp'],
        'nodes'     => array_map('strtoupper', $nodes),
    ];
}

function getLatestConnectedNodes(array $lines): array {
    for ($i = count($lines) - 1; $i >= 0; $i--) {
        if (strpos($lines[$i], 'Connected nodes:') === false) continue;
        $parsed = parseConnectedNodesLine($lines[$i]);
        if ($parsed !== null) return $parsed;
    }
    return [];
}

// ============================================================
// REFLECTOR — ACTIVITÉ (remplace "Derniers appelants")
// ============================================================

function isSVXReflectorActive(array $conf): bool {
    if (isset($conf['ReflectorLogic'])) return true;
    $logics = $conf['GLOBAL']['LOGICS'] ?? '';
    return strpos($logics, 'ReflectorLogic') !== false;
}

function getReflectorActivity(int $max = 50): array {
    return dashboard_cached('reflector_activity_' . $max, 1, function () use ($max) {
        return _computeReflectorActivity($max);
    });
}

function _computeReflectorActivity(int $max = 50): array {
    $logPath = resolveLogPath();
    if (!is_readable($logPath)) return [];

    $lines = @file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return [];

    $pending  = [];
    $sessions = [];

    foreach ($lines as $line) {
        $parsed = parseReflectorLogLine($line);
        if ($parsed === null) continue;

        $key = $parsed['callsign'] . '_' . $parsed['tg'];

        if ($parsed['type'] === 'start') {
            $pending[$key] = $parsed;
            continue;
        }

        if (!isset($pending[$key])) continue;

        $start    = $pending[$key];
        $duration = max(0, $parsed['timestamp'] - $start['timestamp']);

        $sessions[] = [
            'datetime'     => $start['datetime'],
            'timestamp'    => $start['timestamp'],
            'callsign'     => $start['callsign'],
            'callsign_qrz' => $start['callsign_qrz'],
            'is_gateway'   => $start['is_gateway'],
            'tg'           => $start['tg'],
            'tg_name'      => getTGName($start['tg']),
            'duration'     => $duration,
            'active'       => false,
            'entry_type'   => 'rx',
            'source'       => 'talker',
        ];

        unset($pending[$key]);
    }

    foreach ($pending as $start) {
        $sessions[] = [
            'datetime'     => $start['datetime'],
            'timestamp'    => $start['timestamp'],
            'callsign'     => $start['callsign'],
            'callsign_qrz' => $start['callsign_qrz'],
            'is_gateway'   => $start['is_gateway'],
            'tg'           => $start['tg'],
            'tg_name'      => getTGName($start['tg']),
            'duration'     => max(0, time() - $start['timestamp']),
            'active'       => true,
            'entry_type'   => 'tx',
            'source'       => 'talker',
        ];
    }

    $latestNodes = getLatestConnectedNodes($lines);
    if (!empty($latestNodes)) {
        foreach ($latestNodes['nodes'] as $node) {
            $alreadyPresent = false;
            foreach ($sessions as $entry) {
                if ($entry['callsign'] === $node) { $alreadyPresent = true; break; }
            }
            if ($alreadyPresent) continue;

            $fallbackTg = $fallbackTgName = '';
            foreach ($sessions as $session) {
                if ($session['callsign'] === $node && $session['tg'] !== '') {
                    $fallbackTg = $session['tg']; $fallbackTgName = $session['tg_name']; break;
                }
            }
            if ($fallbackTg === '') {
                foreach ($sessions as $session) {
                    if ($session['tg'] !== '') { $fallbackTg = $session['tg']; $fallbackTgName = $session['tg_name']; break; }
                }
            }

            $sessions[] = [
                'datetime'     => $latestNodes['datetime'],
                'timestamp'    => $latestNodes['timestamp'],
                'callsign'     => $node,
                'callsign_qrz' => extractQrzCallsign($node),
                'is_gateway'   => isGatewayNode($node),
                'tg'           => $fallbackTg,
                'tg_name'      => $fallbackTgName,
                'duration'     => 0,
                'active'       => false,
                'entry_type'   => '',
                'source'       => 'connected_node',
            ];
        }
    }

    $grouped = [];
    foreach ($sessions as $session) {
        $key = $session['callsign'] . '|' . $session['tg'];
        if (!isset($grouped[$key])) { $grouped[$key] = $session; continue; }
        if ($session['active'] && !$grouped[$key]['active']) { $grouped[$key] = $session; continue; }
        if ($session['timestamp'] > $grouped[$key]['timestamp']) $grouped[$key] = $session;
    }

    $result = array_values($grouped);

    usort($result, static function (array $a, array $b): int {
        if ($a['active'] !== $b['active']) return $a['active'] ? -1 : 1;
        return $b['timestamp'] <=> $a['timestamp'];
    });

    return array_slice($result, 0, $max);
}

// ============================================================
// NŒUDS CONNECTÉS AU REFLECTOR (page nodes.php)
// ============================================================

function getSVXReflectorNodes(): array {
    return dashboard_cached('svx_reflector_nodes', 5, function () {
        $logPath = resolveLogPath();

        $paths = [$logPath . '.1', $logPath];
        $lines = [];
        foreach ($paths as $path) {
            if (!is_readable($path)) continue;

            $fh = fopen($path, 'r');
            if (!$fh) continue;

            while (($line = fgets($fh)) !== false) {
                if (
                    strpos($line, 'Connected nodes:') !== false ||
                    strpos($line, 'Node joined:') !== false ||
                    strpos($line, 'Node left:') !== false
                ) {
                    $lines[] = rtrim($line);
                }
            }
            fclose($fh);
        }
        $lines = array_slice($lines, -20000);

        $nodes = [];
        $baselineIndex = null;

        // Dernière "photo" Connected nodes: ... dans la fenêtre — point
        // de départ pour ajouter/retirer les joined/left qui suivent.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (strpos($lines[$i], 'Connected nodes:') !== false) {
                $baselineIndex = $i;
                break;
            }
        }

        if ($baselineIndex !== null) {
            $pos = strpos($lines[$baselineIndex], 'Connected nodes:') + strlen('Connected nodes:');
            $listPart = substr($lines[$baselineIndex], $pos);
            foreach (explode(',', $listPart) as $n) {
                $n = trim($n);
                if ($n !== '') $nodes[$n] = true;
            }
        }

        $startFrom = $baselineIndex !== null ? $baselineIndex + 1 : 0;

        for ($i = $startFrom; $i < count($lines); $i++) {
            $line = $lines[$i];

            if (preg_match('/Node joined:\s*([A-Za-z0-9_\-]+)/', $line, $m)) {
                $nodes[$m[1]] = true;
            } elseif (preg_match('/Node left:\s*([A-Za-z0-9_\-]+)/', $line, $m)) {
                unset($nodes[$m[1]]);
            }
        }

        $result = array_keys($nodes);
        sort($result);
        return $result;
    });
}

function getActiveTalkerCallsigns(): array {
    $active = [];
    foreach (getReflectorActivity(50) as $entry) {
        if (!empty($entry['active'])) {
            $active[] = $entry['callsign'];
        }
    }
    return $active;
}

// ============================================================
// ENDPOINT JSON — Données principales du tableau de bord
// URL : include/functions.php?json=1
// ============================================================

if (isset($_GET['json'])) {
    if (ob_get_level()) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    if (!defined('SVXLINK_LOG')) require_once __DIR__ . '/config.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    header('X-Content-Type-Options: nosniff');

    $rfConf   = parse_svxlink_config(SVXLINK_CONFIG);
    $rfActive = isSVXReflectorActive($rfConf);

    $ctcss   = '';
    $modules = [];
    foreach (['SimplexLogic', 'RepeaterLogic'] as $logic) {
        if (!empty($rfConf[$logic]['REPORT_CTCSS'])) {
            $ctcss = $rfConf[$logic]['REPORT_CTCSS'];
        }
        if (!empty($rfConf[$logic]['MODULES'])) {
            $modules = array_map('trim', explode(',', $rfConf[$logic]['MODULES']));
        }
    }

    $currentTg     = getSVXTGSelect();
    $repeaterState = getRepeaterStatus();

    $tgDefault = $rfConf['ReflectorLogic']['DEFAULT_TG']  ?? '';
    $tgMonList = !empty($rfConf['ReflectorLogic']['MONITOR_TGS'])
        ? array_map('trim', explode(',', $rfConf['ReflectorLogic']['MONITOR_TGS']))
        : [];
    $tgTmp     = getSVXTGTMP();

    $response = [
        'svx_status'           => getSvxlinkStatus(),
        'svx_uptime'           => getSvxlinkUptime(),
        'ctcss'                => $ctcss,
        'modules'              => $modules,
        'repeater_runtime'     => $repeaterState,
        'reflector_activity'   => $rfActive ? getReflectorActivity(50) : [],
        'reflector_current_tg' => [
            'tg'      => $currentTg,
            'tg_name' => $currentTg !== '' ? getTGName($currentTg) : '',
            'active'  => $repeaterState['status'] === 'tx',
        ],
        'tg_info' => [
            'default'  => $tgDefault,
            'monitor'  => $tgMonList,
            'tmp'      => $tgTmp,
            'selected' => $currentTg,
        ],
    ];

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================
// ENDPOINT JSON — Repeater Status
// URL : include/functions.php?repeater_status_json=1
// ============================================================

if (isset($_GET['repeater_status_json'])) {
    if (ob_get_level()) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    if (!defined('SVXLINK_LOG')) require_once __DIR__ . '/config.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    echo json_encode(getRepeaterStatus(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ============================================================
// ENDPOINT JSON — Connected Nodes (page nodes.php)
// URL : include/functions.php?nodes_json=1
// ============================================================

if (isset($_GET['nodes_json'])) {
    if (ob_get_level()) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    if (!defined('SVXLINK_LOG')) require_once __DIR__ . '/config.php';

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');
    header('X-Content-Type-Options: nosniff');

    $response = dashboard_cached('endpoint_json_nodes', 1, function () {
        $nodes  = getSVXReflectorNodes();
        $active = getActiveTalkerCallsigns();

        $list = [];
        foreach ($nodes as $node) {
            $list[] = [
                'callsign'     => $node,
                'transmitting' => in_array($node, $active, true),
            ];
        }

        return [
            'nodes' => $list,
            'count' => count($list),
        ];
    });

    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}