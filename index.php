<?php
/**
* Nouvelle version de SvxLink-Dashboard entièrement conçue et développée par CN8VX © 2026.
* SvxLink-Dashboard by CN8VX est conçu pour les répéteurs et hotspots SvxLink. 
* 
* New version of SvxLink-Dashboard fully designed and developed by CN8VX © 2026.
* SvxLink-Dashboard by CN8VX is designed for SvxLink repeaters and hotspots.
*/
require_once __DIR__ . '/include/infosvx.php';
require_once __DIR__ . '/include/hardware_info.php';

$hw             = getAllHardwareInfo();
$repeaterData   = getRepeaterStatus();
$repeaterStatus = $repeaterData['status'];
$rsDesc         = $repeaterData['description'];
$hasLogo        = (LOGO_PATH !== '' && file_exists(__DIR__ . '/' . LOGO_PATH));


// ── Seuils couleur CPU / Temp ─────────────────────────────────
$cpuPct   = $hw['cpu_usage'] ?? 0;
$cpuClass = $cpuPct >= 85 ? 'crit' : ($cpuPct >= 65 ? 'warn' : '');
$cpuVal   = $cpuPct >= 85 ? 'val-crit' : ($cpuPct >= 65 ? 'val-warn' : 'val-ok');

$temp         = isset($hw['cpu_temp']) && $hw['cpu_temp'] !== '' ? (float)$hw['cpu_temp'] : 0;
$tempVal      = $temp >= 70 ? 'val-crit'  : ($temp >= 55 ? 'val-warn'  : 'val-ok');
$tempPanelCls = $temp >= 70 ? 'red'       : ($temp >= 55 ? 'amber'     : 'green');

$ramPct   = $hw['ram']['percent']  ?? 0;
$ramClass = $ramPct  >= 85 ? 'crit' : ($ramPct  >= 65 ? 'warn' : '');

$diskPct   = $hw['disk']['percent'] ?? 0;
$diskClass = $diskPct >= 85 ? 'crit' : ($diskPct >= 65 ? 'warn' : '');

// ── Données pour les barres de progression ─────────────────────
$hw_cpu_usage    = $hw['cpu_usage'] ?? 0;
$cpu_bar_class   = $hw_cpu_usage  > 85 ? 'progress-danger' : ($hw_cpu_usage  > 65 ? 'progress-warning' : 'progress-ok');

$hw_ram_percent  = $hw['ram']['percent']  ?? 0;
$ram_bar_class   = $hw_ram_percent  > 85 ? 'progress-danger' : ($hw_ram_percent  > 65 ? 'progress-warning' : 'progress-ok');

$hw_disk_percent = $hw['disk']['percent'] ?? 0;
$disk_bar_class  = $hw_disk_percent > 85 ? 'progress-danger' : ($hw_disk_percent > 65 ? 'progress-warning' : 'progress-ok');

// ── Uptime SvxLink (valeur initiale, rafraîchi toutes les 30 s par AJAX) ──
$svxUptime = getSvxlinkUptime();

// ── Reflector Activity (remplace "Derniers appelants") ────────
$rfConf     = parse_svxlink_config(SVXLINK_CONFIG);
$rfActive   = isSVXReflectorActive($rfConf);
$rfActivity = $rfActive ? getReflectorActivity(50) : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SvxLink <?php echo htmlspecialchars($repeaterType ?? ''); ?> Repeater Dashboard - <?php echo htmlspecialchars($CALLSIGN); ?></title>
    <link rel="shortcut icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
<?php include __DIR__ . '/include/navbar.php'; ?>
<?php include __DIR__ . '/include/header.php'; ?>

<!-- ══ FREQUENCY Repeater ══════════════════════════════════ -->
  <div class="grid-top fr-pn">

    <div class="freq-panel">
      <div class="freq-block">
        <div class="panel-label panel-bar"><span class="block-icon">🛜</span>Frequency TX / RX <span class="">(MHz)</span></div>
        <div class="freq-main" id="freqEl"><?php echo htmlspecialchars(FREQ_TX); ?>
          <?php if (!empty(FREQ_TX) && !empty(FREQ_RX)): ?> / <?php echo htmlspecialchars(FREQ_RX); ?>
          <?php else: ?><?php echo htmlspecialchars(FREQ_RX); ?><?php endif; ?>
        </div>
        <div class="panel-sub">
          <?php if (!empty($CTCSS)): ?>
            CTCSS <?php echo htmlspecialchars($CTCSS); ?> Hz
          <?php endif; ?>
          <?php if (!empty($CTCSS) && FREQ_OFFSET !== ''): ?> | <?php endif; ?>
          <?php if (FREQ_OFFSET !== ''): ?>
            Offset <?php echo htmlspecialchars(FREQ_OFFSET); ?> MHz
          <?php endif; ?>
        </div>
      </div>
    </div>

<!-- ══ Repeater Status ═════════════════════ -->
    <div class="repeater-status-panel">
      <div class="panel-label panel-bar"><span class="block-icon">🗼</span>Repeater Status</div>
      <div class="rs-bar">
        <div class="rs-state <?php echo $repeaterStatus === 'tx' ? 'tx' : ($repeaterStatus === 'rx' ? 'rx' : 'listening'); ?> active" id="rs-main">
          <?php if ($repeaterStatus !== 'listening'): ?>
            <span class="rs-dot" id="rs-dot"></span>
          <?php else: ?>
            <span class="rs-dot" id="rs-dot" style="display: none;"></span>
          <?php endif; ?>
          <span id="rs-text"><?php
            if      ($repeaterStatus === 'tx') echo 'TX';
            elseif  ($repeaterStatus === 'rx') echo 'RX';
            else    echo 'LISTENING';
          ?></span>
        </div>
      </div>
      <div class="rs-desc" id="rs-desc"><?php echo htmlspecialchars($rsDesc); ?></div>
    </div>

<!-- ══ GRID LEFT : Modules actifs ═══════════════════════════ -->
    <div class="module-panel">
      <div class="panel-label panel-bar"><span class="block-icon">🔓</span>Active Modules</div>
      <div class="module-list">
        <?php if (!empty($MODULES)): ?>
          <?php foreach (array_map('trim', explode(',', $MODULES)) as $mod): ?>
            <span class="module-badge"><?php echo htmlspecialchars($mod); ?></span>
          <?php endforeach; ?>
        <?php else: ?>
          <span class="module-empty">No active modules</span>
        <?php endif; ?>
      </div>
    </div>
  </div>

<!-- ══ GRID MAIN : Uptime / QSO / CPU Temp ══════════════════ -->
  <div class="grid-main">

    <div class="panel">
      <div class="panel-label panel-bar"><span class="block-icon">🕒</span>Uptime</div>
      <div class="panel-value">
        <span class="info-value mono" id="hw-uptime"><?php echo htmlspecialchars($hw['system_uptime']); ?></span>
      </div>
      <div class="panel-sub">Last Reboot</div>
    </div>

    <!-- CPU Temperature -->
    <div class="panel <?php echo $tempPanelCls; ?>" id="cpu-temp-panel">
      <div class="panel-label panel-bar"><span class="block-icon">🌡️</span>CPU Temperature</div>
      <div class="panel-value <?php echo $tempVal; ?>" id="live-cpu-temp">
        <?php echo htmlspecialchars($hw['cpu_temp_display']); ?>
      </div>
      <div class="panel-sub">CPU for Raspberry Pi</div>
    </div>

<!-- Horloge avec Date, Heure et Timezone -->
    <div class="panel clock-panel" id="clock-panel">
      <div class="panel-label panel-bar"><span class="block-icon">⌚</span><span id="clock-date">-- --- ----</span></div>
      <div class="panel-value clock-value" id="clock-time">--:--:--</div>
      <div class="panel-sub">
        <span class="clock-tz" id="clock-tz"><?php echo htmlspecialchars(TIMEZONE); ?></span>
      </div>
    </div>

  </div>

<!-- ══ GRID BOTTOM ══════════════════════════════════════════ -->
  <div class="grid-bottom">

      <!-- ══ Reflector Activity ══ -->
      <div class="panel" id="reflector-panel">
        <div class="panel-label panel-bar">
          <span class="activity-icon">📋</span>Activity from SVXReflector
        </div>

        <?php if (!$rfActive): ?>
          <div class="module-empty">SVXReflector not configured in <code>svxlink.conf</code>.</div>
        <?php elseif (empty($rfActivity)): ?>
          <div class="module-empty">No activity found in the log.</div>
        <?php else: ?>
          <div class="rf-table-container">
            <table class="rf-table" id="reflector-activity-table">
              <thead>
                <tr>
                  <th>Time</th>
                  <th>Callsign</th>
                  <th>TG #</th>
                  <th>TG Name</th>
                  <th>Duration</th>
                </tr>
              </thead>
              <tbody id="reflector-activity-body">
                <?php foreach ($rfActivity as $entry): ?>
                  <tr class="<?php echo $entry['active'] ? 'rf-row-tx' : ''; ?>">

                    <td class="rf-td-time">
                      <?php if ($entry['active']): ?>
                        <span class="tx-indicator-dot" title="In transmission"></span>
                      <?php endif; ?>
                      <?php echo htmlspecialchars($entry['datetime']); ?>
                    </td>

                    <td class="rf-td-cs">
                      <?php if (!empty($entry['callsign'])): ?>
                        <?php if (isQrzCandidate($entry['callsign'])): ?>
                          <a href="https://www.qrz.com/db/<?php echo urlencode($entry['callsign_qrz']); ?>"
                             target="_blank" rel="noopener" class="callsign-link">
                            <?php echo htmlspecialchars($entry['callsign']); ?>
                          </a>
                        <?php else: ?>
                          <span class="<?php echo !empty($entry['is_gateway']) ? 'gateway-name' : ''; ?>">
                            <?php echo htmlspecialchars($entry['callsign']); ?>
                          </span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="no-data">&mdash;</span>
                      <?php endif; ?>
                    </td>

                    <td class="rf-td-tg">
                      <?php echo $entry['tg'] !== '' ? htmlspecialchars($entry['tg']) : '<span class="no-data">&mdash;</span>'; ?>
                    </td>

                    <td class="rf-td-name">
                      <?php echo $entry['tg_name'] !== '' ? htmlspecialchars($entry['tg_name']) : '<span class="no-data">&mdash;</span>'; ?>
                    </td>

                    <td class="rf-td-dur"
                        data-start-ts="<?php echo $entry['active'] ? htmlspecialchars((string)$entry['timestamp']) : '0'; ?>"
                        data-active="<?php echo $entry['active'] ? '1' : '0'; ?>">
                      <?php echo $entry['active'] ? formatDuration((int)$entry['duration']) : formatDuration((int)$entry['duration']); ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

<!-- ══ Reflector & Talk Groups ═════════════════════════════════════ -->
      <div class="right-block">
      <div class="panel">
        <div class="panel-label panel-bar"><span class="block-icon">🌐</span>Reflector &amp; Talk Groups (TG)</div>
        <div class="node-list">
          <div class="node-row">
            <span class="node-name">Callsign on Reflector</span>
            <span class="node-ping callsign-reflector"><?php echo htmlspecialchars($callsignR); ?></span>
          </div>
          <div class="node-row" id="tg-default">
            <span class="node-name">TG Default</span>
            <span class="node-ping tg-default"><?php echo htmlspecialchars($tgdefault ?: 'Not Defined'); ?></span>
          </div>
          <div class="node-row" id="tg-monitor">
            <span class="node-name">TG Monitor</span>
            <span class="node-ping tg-monitor">
              <?php
                if (!empty($tgmon)) {
                    echo htmlspecialchars(implode(', ', array_map('trim', $tgmon)));
                } else {
                    echo 'No monitored TG';
                }
                if ($tgtmp) {
                    echo ' [tmp: ' . htmlspecialchars($tgtmp) . ']';
                }
              ?>
            </span>
          </div>
          <div class="node-row" id="tg-active">
            <span class="node-name">Last Active TG</span>
            <span class="node-ping tg-active"><?php echo htmlspecialchars($tgselect ?: 'waiting...'); ?></span>
          </div>
        </div>
      </div>

<!-- ══ EchoLink — affiché uniquement si ModuleEchoLink est actif ══ -->
      <?php if ($elModActive): ?>
      <div class="panel" id="echolink-panel">
        <?php if (!empty($elConfError)): ?>
          <div class="error-msg"><?php echo $elConfError; ?></div>
        <?php endif; ?>

        <div class="panel-label panel-bar"><span class="block-icon">🔗</span>EchoLink NODE Information</div>
        <div class="node-list">

          <div class="node-row">
            <span class="node-name">Node Callsign</span>
            <span class="node-ping">
              <?php $elBase = extractQrzCallsign($elCallsign); ?>
              <span id="el-callsign">
                <a href="https://www.qrz.com/db/<?php echo urlencode($elBase); ?>"
                   target="_blank" class="callsign-link">
                  <?php echo str_replace('0', '&Oslash;', htmlspecialchars($elCallsign)); ?>
                </a>
              </span>
            </span>
          </div>

          <div class="node-row">
            <span class="node-name">Node Localisation</span>
            <span class="node-ping" id="el-location"><?php echo htmlspecialchars($elLocation); ?></span>
          </div>

          <div class="node-row">
            <span class="node-name">Node Sysop</span>
            <span class="node-ping" id="el-sysop"><?php echo htmlspecialchars($elSysopName); ?></span>
          </div>

          <div class="node-row">
            <span class="node-name">Nodes Connected</span>
            <span class="node-ping" id="el-nodes">
              <?php if (!empty($elUsers)): ?>
                <?php foreach ($elUsers as $ru):
                  $ruBase = extractQrzCallsign($ru); ?>
                  <a href="https://www.qrz.com/db/<?php echo urlencode($ruBase); ?>"
                     target="_blank" class="callsign-link">
                    <?php echo str_replace('0', '&Oslash;', htmlspecialchars($ru)) . ' '; ?>
                  </a>
                <?php endforeach; ?>
              <?php else: ?>
                NO NODE CONNECTED
              <?php endif; ?>
            </span>
          </div>

          <div class="node-row">
            <span class="node-name">Number of Connections</span>
            <span class="node-ping" id="el-count"><?php echo count($elUsers); ?></span>
          </div>

        </div>
      </div>
      <?php endif; ?>

<!-- ══ Hardware Info ════════════════════════════ -->
      <div class="panel">
        <div class="panel-label panel-bar"><span class="block-icon">📟</span>Hardware Info</div>

        <div class="stat-row">
          <span class="stat-key">Hostname</span>
          <span class="stat-val" id="hw-hostname"><?php echo htmlspecialchars($hw['hostname']); ?></span>
        </div>

        <div class="stat-row">
          <span class="stat-key">Local IP</span>
          <span class="stat-val" id="hw-local-ip"><?php echo htmlspecialchars($hw['local_ip']); ?></span>
        </div>

        <div class="stat-row">
          <span class="stat-key">Architecture</span>
          <span class="stat-val" id="hw-cpu-arch"><?php echo htmlspecialchars($hw['cpu_arch']); ?></span>
        </div>

        <div class="stat-row">
          <span class="stat-key">Kernel</span>
          <span class="stat-val" id="hw-kernel"><?php echo htmlspecialchars($hw['kernel']); ?></span>
        </div>

        <div class="stat-row">
          <span class="stat-key">Linux</span>
          <span class="stat-val" id="hw-linux"><?php echo htmlspecialchars($hw['linux_version']); ?></span>
        </div>

        <div class="stat-row">
          <span class="stat-key">SvxLink version</span>
          <span class="stat-val" id="hw-svxlink"><?php echo htmlspecialchars($hw['svxlink_version']); ?></span>
        </div>

        <div class="stat-row">
          <span class="stat-key">Last SvxLink Restart</span>
          <span class="stat-val" id="svx-uptime"><?php echo htmlspecialchars($svxUptime !== '' ? $svxUptime : '--'); ?></span>
        </div>

        <div class="stat-row">
          <span class="stat-key">CPU Cores</span>
          <span class="stat-val" id="hw-cpu-cores"><?php echo htmlspecialchars($hw['cpu_cores'] ?? 'N/A'); ?> Cores</span>
        </div>

        <div class="stat-row">
          <span class="stat-key">CPU Temperature</span>
          <span class="stat-val <?php echo $tempVal; ?>" id="hw-cpu-temp">
            <?php echo htmlspecialchars($hw['cpu_temp_display']); ?>
        </span>
        </div>

        <!-- Barre CPU -->
        <div class="progress-wrap">
          <div class="progress-item">
            <div class="progress-label-row">
              <span>CPU Usage</span>
              <span id="hw-cpu-usage-bar"><?php echo htmlspecialchars($hw['cpu_usage']); ?>%</span>
            </div>
            <div class="progress-track">
              <div class="progress-fill <?php echo $cpu_bar_class; ?>"
                   id="cpu-bar"
                   style="width:<?php echo min(100, $hw_cpu_usage); ?>%"
                   role="progressbar"></div>
            </div>
          </div>
        </div>

        <!-- Barre RAM -->
        <div class="progress-wrap">
          <div class="progress-item">
            <div class="progress-label-row">
              <span>Memory Usage</span>
              <span id="hw-ram-bar-label"><?php echo htmlspecialchars($hw['ram']['used'] ?? '0 MB'); ?> / <?php echo htmlspecialchars($hw['ram']['total'] ?? '0 MB'); ?></span>
            </div>
            <div class="progress-track">
              <div class="progress-fill <?php echo $ram_bar_class; ?>"
                   id="ram-bar"
                   style="width:<?php echo min(100, $hw_ram_percent); ?>%"
                   role="progressbar"></div>
            </div>
          </div>
        </div>

        <!-- Barre Disk -->
        <div class="progress-wrap">
          <div class="progress-item">
            <div class="progress-label-row">
              <span>Disk Usage</span>
              <span id="hw-disk-bar-label"><?php echo htmlspecialchars($hw['disk']['used'] ?? '0 GB'); ?> / <?php echo htmlspecialchars($hw['disk']['total'] ?? '0 GB'); ?></span>
            </div>
            <div class="progress-track">
              <div class="progress-fill <?php echo $disk_bar_class; ?>"
                   id="disk-bar"
                   style="width:<?php echo min(100, $hw_disk_percent); ?>%"
                   role="progressbar"></div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>

<?php include __DIR__ . '/include/footer.php'; ?>

<script>
window.DASH_CONFIG = {
    refresh:       5,
    qrz_enabled:   true,
    qrz_url:       'https://www.qrz.com/db/',
    default_theme: '<?php echo htmlspecialchars(DEFAULT_THEME); ?>'
};
</script>
<script src="scripts/main.js"></script>
</body>
</html>
