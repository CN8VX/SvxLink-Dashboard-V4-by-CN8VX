<?php
/**
 * logsvx.php — SvxLink Dashboard by CN8VX
 * Page de consultation des logs SvxLink avec filtres et rafraîchissement en temps réel.
 * SvxLink log viewer page with filtering options and real-time refresh.
*/

require_once __DIR__ . '/include/config.php';
require_once __DIR__ . '/include/functions.php';
date_default_timezone_set(TIMEZONE);

if (isset($_GET['log_json'])) {
    if (ob_get_level()) ob_end_clean();
    error_reporting(0);
    ini_set('display_errors', 0);

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache');

    $filterType = isset($_GET['type']) ? trim($_GET['type']) : 'all';
    $filterCs   = isset($_GET['cs'])   ? trim($_GET['cs'])   : '';
    $filterTg   = isset($_GET['tg'])   ? trim($_GET['tg'])   : '';
    $filterDate = isset($_GET['date']) ? trim($_GET['date']) : '';

    $all = _loadLogEntries(120 * 5, $filterType, $filterCs, $filterTg, $filterDate);
    $entries = $all['entries'] ?? [];
    echo json_encode([
        'entries' => array_slice($entries, 0, 120),
        'total'   => count($entries),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once __DIR__ . '/include/infosvx.php';

// ── Paramètres GET ─────────────────────────────────────────────
$filterType  = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : 'all'; 
$filterCs    = isset($_GET['cs'])   ? trim($_GET['cs'])   : '';
$filterTg    = isset($_GET['tg'])   ? trim($_GET['tg'])   : '';
$filterDate  = isset($_GET['date']) ? trim($_GET['date']) : '';
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;

$hasActiveFilter = ($filterType !== 'all' || $filterCs !== '' || $filterTg !== '' || $filterDate !== '');

define('LINES_PER_PAGE', 120);

// ═══════════════════════════════════════════════════════════════
//  LECTURE & ANALYSE DU LOG
// ═══════════════════════════════════════════════════════════════

function _classifyLogLine(string $line): ?array {
    $line = trim($line);
    if ($line === '') return null;

    $ts   = extractLogTimestamp($line);
    $time = $ts ? date('H:i:s', $ts['timestamp']) : '??:??:??';
    $date = $ts ? date('d/m/Y', $ts['timestamp']) : '';

    $type = 'info'; $css = 'ev-info'; $tg = ''; $cs = '';

    if (stripos($line, 'Talker start') !== false) {
        $type = 'tx-start'; $css = 'ev-txstart';
        if (preg_match('/TG\s*#?(\d+)/i', $line, $m)) $tg = $m[1];
        if (preg_match('/TG\s*#?\d+\s*:\s*([A-Z0-9][A-Z0-9_-]+)/i', $line, $m)) $cs = strtoupper(trim($m[1]));
    } elseif (stripos($line, 'Talker stop') !== false) {
        $type = 'tx-stop'; $css = 'ev-txstop';
        if (preg_match('/TG\s*#?(\d+)/i', $line, $m)) $tg = $m[1];
        if (preg_match('/TG\s*#?\d+\s*:\s*([A-Z0-9][A-Z0-9_-]+)/i', $line, $m)) $cs = strtoupper(trim($m[1]));
    }
    elseif (stripos($line, 'Selecting') !== false && stripos($line, 'TG #') !== false) {
        $type = 'tg'; $css = 'ev-link';
        if (preg_match('/TG\s*#?(\d+)/i', $line, $m)) $tg = $m[1];
    }
    elseif (stripos($line, 'Module') !== false && preg_match('/activat|deactivat/i', $line)) {
        $type = 'mod'; $css = 'ev-module';
    }
    elseif (stripos($line, 'Turning the transmitter ON') !== false) {
        $type = 'tx-start'; $css = 'ev-txstart';
    } elseif (stripos($line, 'Turning the transmitter OFF') !== false) {
        $type = 'tx-stop'; $css = 'ev-txstop';
    }
    elseif (stripos($line, 'squelch is OPEN') !== false || stripos($line, 'squelch is CLOSED') !== false) {
        $type = 'info'; $css = 'ev-info';
    }
    elseif (stripos($line, 'EchoLink QSO state changed to CONNECTED') !== false) {
        $type = 'link'; $css = 'ev-link';
        if (preg_match('/^\S+\s+\S+\s+\d+\s+[\d:]+\s+\d+:\s+([A-Z0-9][-A-Z0-9]*(?:-[LRlr])?)\s*:/i', $line, $m)) $cs = strtoupper(trim($m[1]));
    } elseif (stripos($line, 'EchoLink QSO state changed to DISCONNECTED') !== false) {
        $type = 'unlink'; $css = 'ev-unlink';
        if (preg_match('/^\S+\s+\S+\s+\d+\s+[\d:]+\s+\d+:\s+([A-Z0-9][-A-Z0-9]*(?:-[LRlr])?)\s*:/i', $line, $m)) $cs = strtoupper(trim($m[1]));
    } elseif (stripos($line, 'EchoLink QSO') !== false) {
        $type = 'link'; $css = 'ev-link';
        if (preg_match('/^\S+\s+\S+\s+\d+\s+[\d:]+\s+\d+:\s+([A-Z0-9][-A-Z0-9]*(?:-[LRlr])?)\s*:/i', $line, $m)) $cs = strtoupper(trim($m[1]));
    }
    elseif (stripos($line, 'ReflectorLogic') !== false) {
        $type = 'info'; $css = 'ev-info';
    }
    elseif (preg_match('/connection established|authentication ok/i', $line)) {
        $type = 'info'; $css = 'ev-info';
    }
    elseif (preg_match('/disconnect|timeout|refused|no route/i', $line)) {
        $type = 'warn'; $css = 'ev-warn';
    }
    elseif (preg_match('/error|warning|failed|fault/i', $line)) {
        $type = 'warn'; $css = 'ev-warn';
    }
    elseif (stripos($line, 'identification') !== false) {
        $type = 'id'; $css = 'ev-info';
    }

    $msg = preg_replace('/^\S+\s+\S+\s+\d+\s+\d{2}:\d{2}:\d{2}\s+\d{4}\s*:\s*/', '', $line);
    if ($msg === $line) $msg = preg_replace('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\s*:\s*/', '', $line);
    if ($msg === $line) $msg = preg_replace('/^\S+\s+\S+\s+\d+\s+\d{2}:\d{2}:\d{2}\s*:\s*/', '', $line);

    return compact('time', 'date', 'type', 'css', 'tg', 'cs', 'msg', 'line')
        + ['timestamp' => $ts ? $ts['timestamp'] : 0];
}

/**
* Charge les N dernières entrées du log, filtrées.
*/
function _loadLogEntries(int $max, string $filterType, string $filterCs, string $filterTg, string $filterDate = ''): array {
    $logPath = resolveLogPath();

    if (!is_readable($logPath)) {
        return ['error' => 'Log file not readable: ' . $logPath, 'entries' => []];
    }

    $dateFrom = 0;
    $dateTo   = 0;
    if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate)) {
        $dateFrom = (int)strtotime($filterDate . ' 00:00:00');
        $dateTo   = (int)strtotime($filterDate . ' 23:59:59');
    }

    $lines      = [];
    $handle     = @fopen($logPath, 'r');
    $bufferSize = 8192;
    $targetLines = $max * 3; 

    if ($handle) {
        fseek($handle, 0, SEEK_END);
        $position  = ftell($handle);
        $linesRead = 0;

        while ($position > 0 && $linesRead < $targetLines) {
            $readSize   = min($bufferSize, $position);
            $position  -= $readSize;
            fseek($handle, $position);
            $chunk      = fread($handle, $readSize);
            $chunkLines = explode("\n", $chunk);
            $linesRead += count($chunkLines);
            $lines      = array_merge($chunkLines, $lines);
        }
        fclose($handle);
    }

    $logPathRotated = $logPath . '.1';
    if (is_readable($logPathRotated)) {
        $cmd          = 'LC_ALL=C LANG=C tail -' . (int)$targetLines . ' ' . escapeshellarg($logPathRotated);
        $rotatedOut   = @shell_exec($cmd);
        if ($rotatedOut !== null && $rotatedOut !== '') {
            $rotatedLines = explode("\n", trim($rotatedOut));
            $lines = array_merge($rotatedLines, $lines);
        }
    }

    $entries = [];
    foreach ($lines as $line) {
        $e = _classifyLogLine($line);
        if ($e === null) continue;

        if ($filterType !== 'all' && $e['type'] !== strtolower($filterType)) continue;
        if ($filterCs !== '' && stripos($e['cs'],   $filterCs) === false
                             && stripos($e['line'],  $filterCs) === false) continue;
        if ($filterTg !== '' && $e['tg'] !== $filterTg) continue;
        if ($dateFrom > 0 && ($e['timestamp'] < $dateFrom || $e['timestamp'] > $dateTo)) continue;

        $entries[] = $e;
    }

    usort($entries, static fn($a, $b) => $b['timestamp'] <=> $a['timestamp']);

    return ['entries' => array_slice($entries, 0, $max)];
}

$logData      = _loadLogEntries(LINES_PER_PAGE * 50, $filterType, $filterCs, $filterTg, $filterDate);
$allEntries   = $logData['entries'] ?? [];
$logError     = $logData['error']   ?? '';
$totalEntries = count($allEntries);

$totalPages  = max(1, (int)ceil($totalEntries / LINES_PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset      = ($currentPage - 1) * LINES_PER_PAGE;
$entries     = array_slice($allEntries, $offset, LINES_PER_PAGE);

$isMainPage    = ($currentPage === 1);
$liveAllowed   = $isMainPage && !$hasActiveFilter;

function _buildPageUrl(int $p, string $type, string $cs, string $tg, string $date = ''): string {
    $params = [];
    if ($type !== 'all') $params['type'] = $type;
    if ($cs   !== '')    $params['cs']   = $cs;
    if ($tg   !== '')    $params['tg']   = $tg;
    if ($date !== '')    $params['date'] = $date;
    if ($p    > 1)       $params['page'] = $p;
    $q = http_build_query($params);
    return 'logsvx.php' . ($q ? '?' . $q : '');
}

$hasLogo = (LOGO_PATH !== '' && file_exists(__DIR__ . '/' . LOGO_PATH));

$stats = ['tx-start'=>0,'tx-stop'=>0,'link'=>0,'unlink'=>0,'warn'=>0,'tg'=>0,'mod'=>0,'id'=>0,'info'=>0];
foreach ($allEntries as $e) {
    $k = strtolower($e['type']);
    if (isset($stats[$k])) $stats[$k]++;
    else $stats['info']++;
}

$statDefs = [
    'tx-start' => ['TX-START', 'ev-txstart', '📡'],
    'tx-stop'  => ['TX-STOP',  'ev-txstop',  '🔕'],
    'tg'       => ['TG',       'ev-link',    '🌐'],
    'warn'     => ['WARN',     'ev-warn',    '⚠️'],
    'mod'      => ['MOD',      'ev-module',  '🔓'],
    'link'     => ['LINK',     'ev-link',    '🔗'],
    'unlink'   => ['UNLINK',   'ev-unlink',  '🔌'],
    'info'     => ['INFO',     'ev-info',    'ℹ️'],
];

$svxStatus = getSvxlinkStatus();


function _rowClass(string $type): string {
    return match($type) {
        'tx-start' => 'log-row-tx',
        'tx-stop'  => 'log-row-rx',
        'link'     => 'log-row-link',
        'unlink'   => 'log-row-unlink',
        'warn'     => 'log-row-warn',
        default    => '',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SvxLink <?php echo htmlspecialchars($repeaterType ?? ''); ?> Repeater Log Viewer — <?php echo htmlspecialchars($CALLSIGN); ?></title>
    <link rel="shortcut icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
    <script src="scripts/main.js"></script>
</head>
<body>
<?php $activeNav = 'activity'; include __DIR__ . '/include/navbar.php'; ?> 
<div id="root" class="dark-bg">

  <!-- ══ TOP BAR ══════════════════════════════════════════════ -->
  <div class="top-bar">
    <div class="header-main">
        <?php if ($hasLogo): ?>
            <img src="<?php echo htmlspecialchars(LOGO_PATH); ?>"
                 alt="Logo <?php echo htmlspecialchars($CALLSIGN); ?>"
                 class="header-logo">
        <?php else: ?>
            <div class="header-logo-text"><?php echo htmlspecialchars($CALLSIGN); ?></div>
        <?php endif; ?>
        <div>
            <div class="header-titles">SvxLink Logs Viewer for Repeater :
                <span class="header-callsign"><?php echo htmlspecialchars($CALLSIGN); ?></span>
            </div>
            <div class="header-subtitle">
                <span class="block-icon">📂</span><?php echo htmlspecialchars(SVXLINK_LOG); ?>
                &nbsp;|&nbsp;
                <span class="block-icon">🕒</span><?php echo htmlspecialchars(TIMEZONE); ?>
            </div>
        </div>
    </div>
    <div class="status-row">
        <div id="statusLabel" class="status-label <?php
            echo $svxStatus === 'active' ? 'active' : ($svxStatus === 'inactive' ? 'inactive' : 'failed'); ?>">
            <span id="rxdot" class="status-dot"></span>
            <span id="statusText"><?php echo strtoupper($svxStatus ?: 'UNKNOWN'); ?></span>
        </div>
        <div class="header-clock" id="header-clock">--:--</div>
        <button class="theme-btn" id="theme-toggle" title="Change Theme" aria-label="Toggle dark/light theme">
            <span class="theme-icon">🌙</span>
        </button>
    </div>
  </div>

  <!-- ── Toolbar ─────────────────────────────────────────────── -->
  <div class="log-page-wrap">
    <form method="get" id="log-filter-form">
    <div class="log-toolbar">

        <label for="f-type">Type</label>
        <select name="type" id="f-type" onchange="this.form.submit()">
            <?php foreach (['all','tx-start','tx-stop','tg','warn','mod','link','unlink','info'] as $opt): ?>
            <option value="<?php echo $opt; ?>" <?php echo $filterType === $opt ? 'selected' : ''; ?>>
                <?php echo strtoupper($opt); ?>
            </option>
            <?php endforeach; ?>
        </select>

        <label for="f-cs">Callsign</label>
        <input type="text" id="f-cs" name="cs" placeholder="ex: CN8VX"
               value="<?php echo htmlspecialchars($filterCs); ?>" autocomplete="off">

        <label for="f-tg">TG #</label>
        <input type="text" id="f-tg" name="tg" placeholder="ex: 604"
               value="<?php echo htmlspecialchars($filterTg); ?>" autocomplete="off">

        <label for="f-date">Date</label>
        <input type="date" id="f-date" name="date"
               value="<?php echo htmlspecialchars($filterDate); ?>"
               title="Filtrer par date (00:00 → 23:59)">

        <button type="submit" class="log-btn">🔍 Filter</button>

        <?php if ($hasActiveFilter): ?>
        <a href="logsvx.php" class="log-btn danger">✕ Reset</a>
        <?php endif; ?>

        <div class="toolbar-sep"></div>

        <?php if ($liveAllowed): ?>
        <button type="button" class="log-btn green" id="btn-live" onclick="toggleLive()">
            <span class="live-dot" id="live-dot"></span>Live
        </button>
        <?php elseif ($hasActiveFilter): ?>
        <span class="pag-live-off" title="Auto-Refresh désactivé lors d'un filtrage">Auto-Refresh OFF — filtered view</span>
        <?php else: ?>
        <span class="pag-live-off">Auto-Refresh OFF — page <?php echo $currentPage; ?></span>
        <?php endif; ?>

    </div>
    </form>

    <div class="log-stats">
        <?php foreach ($statDefs as $key => [$label, $evCss, $icon]):
            $count    = $stats[$key] ?? 0;
            $isActive = $filterType === $key ? 'active-filter' : '';
        ?>
        <a href="<?php echo htmlspecialchars(_buildPageUrl(1, $key, $filterCs, $filterTg, $filterDate)); ?>"
           class="log-stat-badge <?php echo $isActive; ?>">
            <?php echo $icon; ?>
            <span><?php echo $label; ?></span>
            <span class="stat-badge-count log-type <?php echo $evCss; ?>"><?php echo $count; ?></span>
        </a>
        <?php endforeach; ?>
        <span style="flex:1"></span>
        <span class="log-entries">Total Entries</span>
        <span class="log-count-badge"><?php echo $totalEntries; ?> entries</span>
    </div>

    <div class="log-panel-header">
        <div class="log-panel-title">
            <span class="log-entries-icon">📋</span>Log Entries
            <?php if ($hasActiveFilter): ?>
                <span class="log-count-badge1">Filtered</span>
            <?php endif; ?>
            <?php if ($filterDate !== ''): ?>
                <span class="log-count-badge1 filtre-date">
                    📅 <?php echo htmlspecialchars(date('d/m/Y', strtotime($filterDate))); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php if ($totalEntries > 0): ?>
            <div class="info-pag">
                <?php if ($totalPages > 1): ?>
                    Page <?php echo $currentPage; ?>/<?php echo $totalPages; ?> &nbsp;=>&nbsp;
                <?php endif; ?>
                <?php echo count($entries); ?> lines on <?php echo $totalEntries; ?> entries
            </div>
        <?php else: ?>
            <div class="info-pag">0 entries</div>
        <?php endif; ?>
    </div>

    <?php if ($logError !== ''): ?>
    <div class="panel" style="padding:16px; color:var(--rf-amber);">
        ⚠️ <?php echo htmlspecialchars($logError); ?>
    </div>
    <?php endif; ?>

    <div class="log-table-wrap">
      <table class="log-table">
        <thead>
          <tr>
            <th>Date</th>
            <th>Time</th>
            <th>Type</th>
            <th>Callsign</th>
            <th>TG #</th>
            <th>Message</th>
            <th class="col-raw">Raw Line</th>
          </tr>
        </thead>
        <tbody id="log-tbody">
          <?php if (empty($entries)): ?>
          <tr><td colspan="7" class="log-empty">No log entries found.</td></tr>
          <?php else: ?>
          <?php foreach ($entries as $e): ?>
          <tr class="<?php echo _rowClass($e['type']); ?>">
            <td class="td-date"><?php echo htmlspecialchars($e['date']); ?></td>
            <td class="td-time"><?php echo htmlspecialchars($e['time']); ?></td>
            <td class="td-type">
              <span class="log-type <?php echo htmlspecialchars($e['css']); ?>">
                <?php echo strtoupper(htmlspecialchars($e['type'])); ?>
              </span>
            </td>
            <td class="td-cs">
              <?php if (!empty($e['cs'])): $csBase = extractQrzCallsign($e['cs']); ?>
                <a href="https://www.qrz.com/db/<?php echo urlencode($csBase); ?>"
                   target="_blank" rel="noopener" class="callsign-link">
                   <?php echo htmlspecialchars($e['cs']); ?>
                </a>
              <?php else: ?><span style="color:var(--rf-muted)">—</span><?php endif; ?>
            </td>
            <td class="td-tg">
              <?php echo $e['tg'] !== '' ? htmlspecialchars($e['tg']) : '<span style="color:var(--rf-muted)">—</span>'; ?>
            </td>
            <td class="td-msg"><?php echo htmlspecialchars($e['msg']); ?></td>
            <td class="td-raw" title="Click to expand"
                onclick="this.style.maxWidth='none';this.style.whiteSpace='normal'">
              <?php echo htmlspecialchars($e['line']); ?>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="pagination-info">
        <span class="pag-info">
            <?php if ($totalPages > 1): ?>
                Page <?php echo $currentPage; ?>/<?php echo $totalPages; ?>,
            <?php endif; ?>
            <?php echo $totalEntries; ?> entries
            <?php if ($totalEntries > 0): ?>
                — displaying <?php echo $offset + 1; ?> to <?php echo min($offset + LINES_PER_PAGE, $totalEntries); ?>
            <?php endif; ?>
        </span>
    </div>

    <?php if ($totalPages > 1):
        $window = 2;
    ?>
    <div class="pagination" aria-label="Log pagination">

        <?php if ($currentPage > 1): ?>
        <a href="<?php echo htmlspecialchars(_buildPageUrl($currentPage - 1, $filterType, $filterCs, $filterTg, $filterDate)); ?>"
           class="page-btn" aria-label="Previous">&laquo; Prev</a>
        <?php endif; ?>

        <?php if ($currentPage > $window + 1): ?>
        <a href="<?php echo htmlspecialchars(_buildPageUrl(1, $filterType, $filterCs, $filterTg, $filterDate)); ?>"
           class="page-btn">1</a>
        <?php if ($currentPage > $window + 2): ?>
        <span class="page-ellipsis">…</span>
        <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = max(1, $currentPage - $window); $p <= min($totalPages, $currentPage + $window); $p++): ?>
        <?php if ($p === $currentPage): ?>
        <span class="page-btn active" aria-current="page"><?php echo $p; ?></span>
        <?php else: ?>
        <a href="<?php echo htmlspecialchars(_buildPageUrl($p, $filterType, $filterCs, $filterTg, $filterDate)); ?>"
           class="page-btn"><?php echo $p; ?></a>
        <?php endif; ?>
        <?php endfor; ?>

        <?php if ($currentPage < $totalPages - $window): ?>
        <?php if ($currentPage < $totalPages - $window - 1): ?>
        <span class="page-ellipsis">…</span>
        <?php endif; ?>
        <a href="<?php echo htmlspecialchars(_buildPageUrl($totalPages, $filterType, $filterCs, $filterTg, $filterDate)); ?>"
           class="page-btn"><?php echo $totalPages; ?></a>
        <?php endif; ?>

        <?php if ($currentPage < $totalPages): ?>
        <a href="<?php echo htmlspecialchars(_buildPageUrl($currentPage + 1, $filterType, $filterCs, $filterTg, $filterDate)); ?>"
           class="page-btn" aria-label="Next">Next &raquo;</a>
        <?php endif; ?>

    </div>
    <?php endif; ?>

  </div><!-- end log-page-wrap -->
</div><!-- end root -->

<?php include __DIR__ . '/include/footer.php'; ?>

<script>
'use strict';

var DASH_DEFAULT_THEME = '<?php echo htmlspecialchars(DEFAULT_THEME); ?>';

var IS_MAIN_PAGE     = <?php echo $isMainPage    ? 'true' : 'false'; ?>;
var HAS_ACTIVE_FILTER = <?php echo $hasActiveFilter ? 'true' : 'false'; ?>;
var LIVE_ALLOWED     = <?php echo $liveAllowed   ? 'true' : 'false'; ?>;

var LIVE_INT     = 10000; 
var liveEnabled  = LIVE_ALLOWED; 
var liveTimer    = null;
var requestInFlight = false; 

var PARAMS = {
    type: '<?php echo addslashes($filterType); ?>',
    cs:   '<?php echo addslashes($filterCs); ?>',
    tg:   '<?php echo addslashes($filterTg); ?>',
    date: '<?php echo addslashes($filterDate); ?>'
};

function buildUrl() {
    return 'logsvx.php?log_json=1'
        + '&type=' + encodeURIComponent(PARAMS.type)
        + '&cs='   + encodeURIComponent(PARAMS.cs)
        + '&tg='   + encodeURIComponent(PARAMS.tg)
        + '&date=' + encodeURIComponent(PARAMS.date)
        + '&_='    + Date.now();
}

function fetchLogs() {
    if (!LIVE_ALLOWED) return;
    if (requestInFlight) return;
    requestInFlight = true;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', buildUrl(), true);
    xhr.timeout = 8000;
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4) return;
        requestInFlight = false;
        if (xhr.status !== 200) return;
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.entries) renderTable(data.entries);
            var countEl = document.getElementById('total-count');
            if (countEl && data.total !== undefined) countEl.textContent = data.total + ' entries';
        } catch(e) {}
    };
    xhr.ontimeout = xhr.onerror = function() { requestInFlight = false; };
    xhr.send();
}

function renderTable(entries) {
    var tbody = document.getElementById('log-tbody');
    if (!tbody) return;
    if (!entries.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="log-empty">No log entries found.</td></tr>';
        return;
    }

    var rcMap = {
        'tx-start': 'log-row-tx',
        'tx-stop':  'log-row-rx',
        'warn':     'log-row-warn',
        'link':     'log-row-link',
        'unlink':   'log-row-unlink'
    };

    var html = '';
    entries.forEach(function(e) {
        var typeKey = (e.type || '').toLowerCase();
        var cs = e.cs
            ? '<a href="https://www.qrz.com/db/' + encodeURIComponent(toQrzCallsign(e.cs))
              + '" target="_blank" rel="noopener" class="callsign-link">' + escHtml(e.cs) + '</a>'
            : '<span style="color:var(--rf-muted)">—</span>';
        var tg = e.tg ? escHtml(e.tg) : '<span style="color:var(--rf-muted)">—</span>';

        html += '<tr class="' + (rcMap[typeKey] || '') + '">'
            + '<td class="td-date">'  + escHtml(e.date || '') + '</td>'
            + '<td class="td-time">'  + escHtml(e.time || '') + '</td>'
            + '<td class="td-type"><span class="log-type ' + escHtml(e.css || '') + '">'
            +   (e.type || '').toUpperCase() + '</span></td>'
            + '<td class="td-cs">'  + cs + '</td>'
            + '<td class="td-tg">'  + tg + '</td>'
            + '<td class="td-msg">' + escHtml(e.msg  || '') + '</td>'
            + '<td class="td-raw" title="Click to expand"'
            +    ' onclick="this.style.maxWidth=\'none\';this.style.whiteSpace=\'normal\'">'
            +    escHtml(e.line || '') + '</td>'
            + '</tr>';
    });
    tbody.innerHTML = html;
}

function scheduleLive() {
    if (liveTimer) { clearTimeout(liveTimer); liveTimer = null; }
    if (!liveEnabled || !LIVE_ALLOWED) return;
    liveTimer = setTimeout(function() {
        fetchLogs();
        scheduleLive();
    }, LIVE_INT);
}

function toggleLive() {
    if (!LIVE_ALLOWED) return;
    liveEnabled = !liveEnabled;
    var btn = document.getElementById('btn-live');
    if (liveEnabled) {
        if (btn) {
            btn.innerHTML = '<span class="live-dot" id="live-dot"></span>Live';
            btn.classList.remove('amber');
            btn.classList.add('green');
        }
        scheduleLive();
        fetchLogs();
    } else {
        if (btn) {
            btn.innerHTML = '<span class="live-dot paused" id="live-dot"></span>Paused';
            btn.classList.remove('green');
            btn.classList.add('amber');
        }
        if (liveTimer) { clearTimeout(liveTimer); liveTimer = null; }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    if (LIVE_ALLOWED) {
        fetchLogs();
        scheduleLive();
    }
    if (HAS_ACTIVE_FILTER && liveTimer) {
        clearTimeout(liveTimer);
        liveTimer = null;
    }
});
</script>
</body>
</html>