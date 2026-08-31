<?php
/**
 * ============================================================
 *  SvxLink Dashboard by CN8VX © 2026 — Configuration File
 *  Editez ce fichier selon votre installation.
 *  Edit this file according to your setup.
 * ============================================================
 */

// ============================================================
//  CONFIGURATION VISUELLE / VISUAL CONFIGURATION
// ============================================================

// Logo path / Chemin du logo
define('LOGO_PATH', 'images/logo.png');

// tile / subtitle of the dashboard
// Titre / Sous-titre du dashboard
define('DASHBOARD_SUBTITLE', 'Analog-FM Repeater');

// Call sign displayed in the header (leave empty to hide)
// QTH affiché dans le header (laisser vide pour ne pas afficher)
define('HEADER_QTH', 'country, city');

// Fréquence d'émission TX en MHz (saisie manuelle) du NODE ou répéteur.
// TX transmission frequency in MHz (manual entry) for the NODE or repeater.
define('FREQ_TX', '---.---');

// Fréquence de réception RX en MHz (saisie manuelle) du répéteur.
// RX reception frequency in MHz (manual entry) for the repeater.
// Pour les NODE simplex : laisser vide.
// For simplex NODEs: leave empty.
define('FREQ_RX', '');


// Frequency offset in kHz — positive or negative (e.g., -600 or +600). Leave empty if not applicable.
// Offset en kHz — positif ou négatif (ex: -600 ou +600). Laisser '' si non applicable.
define('FREQ_OFFSET', '');

// Default theme on page load: 'dark' or 'light'
// Thème par défaut au chargement : 'dark' ou 'light'
define('DEFAULT_THEME', 'dark');

// ============================================================
//  CHEMINS / PATHS
// ============================================================

// Configuration files for SvxLink
// Fichier de configuration SvxLink
define('SVXLINK_CONFIG', '/etc/svxlink/svxlink.conf');

// log file for SvxLink
// Fichier de log SvxLink
define('SVXLINK_LOG', '/var/log/svxlink');


// ============================================================
//  INFORMATIONS SYSOP / SYSOP INFORMATION
// ============================================================

// System operator callsign
// Indicatif du SYSOP du système
$SYSOP = "Your-CALL";

// System operator name
// Nom du SYSOP
$SYSOPNAME = "Your-Name";

// ========================================
// CPU TEMPERATURE UNIT: 'C' or 'F'
// ========================================

// CPU temperature offset in °C — useful on boards whose sensor reads
// off (e.g. Orange Pi). Leave at 0 if not needed.
// Offset de température CPU en °C — utile sur certaines cartes
// (ex: Orange Pi) dont le capteur est décalé. Laisser à 0 si inutile.
define('CPU_TEMP_OFFSET', 0);

// - Unité de température CPU affichée : 'C' ou 'F'.
// Displayed CPU temperature unit: 'C' or 'F'.
// - Les seuils de couleur (index.php) sont toujours en Celsius.
// Color thresholds (index.php) always use Celsius.
// - Cette constante affecte uniquement l'affichage utilisateur
// - (formatTempDisplay() dans hardware_info.php).
// This constant only affects the user display
// (formatTempDisplay() in hardware_info.php).
define('TEMP_UNIT', 'C');


// ============================================================
//  FUSEAU HORAIRE / TIMEZONE
// ============================================================

// Fuseau horaire lu automatiquement depuis /etc/localtime (celui du
// système). Rempli sur Africa/Casablanca si indisponible.
// Timezone auto-detected from /etc/localtime (system timezone).
// Falls back to Africa/Casablanca if unavailable.
if (file_exists('/etc/localtime') && is_link('/etc/localtime')) {
    $systemTimezoneLink = readlink('/etc/localtime');
    $systemTimezone     = $systemTimezoneLink !== false
        ? substr($systemTimezoneLink, strpos($systemTimezoneLink, 'zoneinfo/') + 9)
        : false;
} else {
    $systemTimezone = false;
}

if ($systemTimezone !== false && $systemTimezone !== '' && in_array($systemTimezone, timezone_identifiers_list(), true)) {
    define('TIMEZONE', $systemTimezone);
} else {
    define('TIMEZONE', 'Africa/Casablanca');
}
unset($systemTimezoneLink, $systemTimezone);

date_default_timezone_set(TIMEZONE);


