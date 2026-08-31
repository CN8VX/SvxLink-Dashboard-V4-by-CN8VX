<?php
/**
 * infosvx_V4 — SvxLink Dashboard by CN8VX © 2026
 * Compatible Debian 12/13, Raspbian Bookworm/Trixie et versions ultérieures.
 * Compatible with Debian 12/13, Raspbian Bookworm/Trixie and later versions.
 *
 * Extraction et affichage des informations de configuration et d'état de SvxLink.
 * Extracts and displays SvxLink configuration and status information.
*/

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

// ========================================================
// PARSE CONFIG
// ========================================================

$config = parse_svxlink_config(SVXLINK_CONFIG);

// ========================================================
// INIT
// ========================================================

$CALLSIGN  = 'CALLSIGN';
$LOGICS    = '';
$LOGICSR   = '';
$LOGICSC   = 'Not Connected';
$callsignR = 'No Connected';
$MODULES   = '';
$CTCSS = '';

// ========================================================
// LOGICS
// ========================================================

if (!empty($config['GLOBAL']['LOGICS'])) {

    $logics = array_map('trim', explode(',', $config['GLOBAL']['LOGICS']));

    foreach ($logics as $logic) {

        if ($logic === 'SimplexLogic' && !empty($config['SimplexLogic']['CALLSIGN'])) {
            $CALLSIGN = $config['SimplexLogic']['CALLSIGN'];
            $LOGICS  .= 'SimplexLogic, ';
            $MODULES  = $config['SimplexLogic']['MODULES'] ?? '';
            $CTCSS = $config['SimplexLogic']['REPORT_CTCSS'] ?? '';
        }

        if ($logic === 'RepeaterLogic' && !empty($config['RepeaterLogic']['CALLSIGN'])) {
            $CALLSIGN = $config['RepeaterLogic']['CALLSIGN'];
            $LOGICS  .= 'RepeaterLogic, ';
            $MODULES  = $config['RepeaterLogic']['MODULES'] ?? '';
            $CTCSS = $config['RepeaterLogic']['REPORT_CTCSS'] ?? '';
        }

        if ($logic === 'ReflectorLogic') {
            $LOGICSR = 'ReflectorLogic';

            if (!empty($config['ReflectorLogic']['CALLSIGN'])) {
                $callsignR = $config['ReflectorLogic']['CALLSIGN'];
                $LOGICSC   = 'Connected';
            }
        }
    }

    $LOGICS = rtrim($LOGICS, ', ');
}
    $repeaterType = '';
    if (str_contains($LOGICS, 'SimplexLogic'))  $repeaterType = 'Simplex';
    if (str_contains($LOGICS, 'RepeaterLogic')) $repeaterType = 'Duplex';

// ========================================================
// TG
// ========================================================

$tgdefault = $config['ReflectorLogic']['DEFAULT_TG']  ?? '';
$tgmon     = !empty($config['ReflectorLogic']['MONITOR_TGS'])
    ? explode(',', $config['ReflectorLogic']['MONITOR_TGS'])
    : [];

$tgtmp    = getSVXTGTMP();
$tgselect = getSVXTGSelect();

// ========================================================
// ECHOLINK CONFIG
// ========================================================

$elModActive = false;
$elCallsign  = '';
$elSysopName = '';
$elLocation  = '';

if (!empty($MODULES)) {
    $mods = array_map('trim', explode(',', $MODULES));
    $elModActive = in_array('ModuleEchoLink', $mods);
}

$echolinkConfPath = '/etc/svxlink/svxlink.d/ModuleEchoLink.conf';
$elConfError = '';

if ($elModActive) {
    if (file_exists($echolinkConfPath)) {
        $elConfig     = parse_svxlink_config($echolinkConfPath);
        $elCallsign   = $elConfig['ModuleEchoLink']['CALLSIGN']  ?? '';
        $elSysopName  = $elConfig['ModuleEchoLink']['SYSOPNAME'] ?? '';
        $elLocation   = $elConfig['ModuleEchoLink']['LOCATION']  ?? '';
    } else { /** Si le Fichier de config introuvable */
        $elConfError = "Error: EchoLink configuration file not found.";
    }
}

if ($elModActive && file_exists($echolinkConfPath)) {
    $elConfig     = parse_svxlink_config($echolinkConfPath);
    $elCallsign   = $elConfig['ModuleEchoLink']['CALLSIGN']  ?? '';
    $elSysopName  = $elConfig['ModuleEchoLink']['SYSOPNAME'] ?? '';
    $elLocation   = $elConfig['ModuleEchoLink']['LOCATION']  ?? '';
}

// ========================================================
// ECHOLINK RUNTIME
// ========================================================

$elUsers = [];
$elTxing = '';

if ($elModActive && isProcessRunning('svxlink')) {
    $log      = getEchoLog();
    $elUsers  = getConnectedEcholink($log);
    $elTxing  = getEchoLinkTX();
}

// ========================================================
// EXPORT (UTILISATION EXTERNE)
// ========================================================

/* sortie brute pour utilisation dans d'autres scripts ou affichage direct.
Variables disponibles :

$CALLSIGN   → Callsign Repeater
$LOGICS     → Repeater Type (Simplex / Repeater)
$repeaterType  → "Simplex" ou "Duplex"
$LOGICSR    → ReflectorLogic
$LOGICSC    → Connected / Not Connected
$callsignR  → Callsign reflector
$CTCSS      → Tone CTCSS actif

$MODULES    → Modules actifs

$tgdefault  → TG par défaut
$tgmon      → TG monitor (array)
$tgselect   → TG actif
$tgtmp      → TG temporaire

$elModActive → bool
$elCallsign  → Callsign EchoLink
$elSysopName
$elLocation
$elUsers     → array
$elTxing     → callsign TX
$elConfError → Message d'erreur si le fichier de config est introuvable
*/
/* sortie formatée pour affichage  :
<?php if (!empty($CTCSS)): ?>CTCSS <?php echo htmlspecialchars($CTCSS); ?> Hz<?php endif; ?>

echo "TG Monitor : " . (!empty($tgmon) ? implode(', ', $tgmon) : 'No monitored TG') . "<br>";

if (!empty($tgmon)) {
    echo htmlspecialchars(implode(', ', array_map('trim', $tgmon)));
} else {
    echo 'No monitored TG';
}
if ($tgtmp) {
    echo ' [tmp: ' . htmlspecialchars($tgtmp) . ']</span>';
}

*/
