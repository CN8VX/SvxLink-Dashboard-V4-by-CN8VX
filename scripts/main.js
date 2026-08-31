/**
 * SvxLink Dashboard — scripts/main.js
 * Horloge instantanée, refresh CPU Temp, EchoLink, Hardware Info.
 */

'use strict';

var CFG = window.DASH_CONFIG || {
    refresh:       5,
    qrz_enabled:   true,
    qrz_url:       'https://www.qrz.com/db/',
    default_theme: 'dark'
};

var scriptTag = document.querySelector('script[src*="main.js"]');
var scriptSrc = scriptTag ? scriptTag.getAttribute('src') : 'scripts/main.js';
var webRoot   = scriptSrc.indexOf('../') === 0 ? '../' : '';

var JSON_ENDPOINT            = webRoot + 'include/functions.php?json=1';
var ECHOLINK_ENDPOINT        = webRoot + 'include/functions.php?echolink_json=1';
var HARDWARE_ENDPOINT        = webRoot + 'include/hardware_info.php?json=1';
var HARDWARE_LIVE_ENDPOINT   = webRoot + 'include/hardware_live.php';
var UPTIME_ENDPOINT          = webRoot + 'include/functions.php?uptime_json=1';
var REPEATER_STATUS_ENDPOINT = webRoot + 'include/functions.php?repeater_status_json=1';
var NODES_ENDPOINT           = webRoot + 'include/functions.php?nodes_json=1';

var repeaterFastPollTimer  = null;
var currentRepeaterStatus  = 'listening';
var _lastCpuSnapshot = null;


// ════════════════════════════════════════════════════════
//  CLOCK — real-time, ticks every second
// ════════════════════════════════════════════════════════

function startRealTimeClock() {
    function updateClock() {
        var now = new Date();

        var timeStr = now.toLocaleTimeString('fr-FR', {
            hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
        });

        var dateStr = now.toLocaleDateString('fr-FR', {
            weekday: 'short', day: '2-digit', month: 'short', year: 'numeric'
        });
        dateStr = dateStr.charAt(0).toUpperCase() + dateStr.slice(1);

        var timeEl = document.getElementById('clock-time');
        var dateEl = document.getElementById('clock-date');
        if (timeEl) timeEl.textContent = timeStr;
        if (dateEl) dateEl.textContent = dateStr;
    }
    updateClock();
    setInterval(updateClock, 1000);
}

function startClock() {
    function tick() {
        var t = new Date().toLocaleTimeString('fr-FR', {
            hour: '2-digit', minute: '2-digit', hour12: false
        });
        var el = document.getElementById('header-clock');
        if (el) el.textContent = 'Local Time: ' + t;
    }
    tick();
    setInterval(tick, 1000);
}

// ════════════════════════════════════════════════════════
//  UTILITIES
// ════════════════════════════════════════════════════════

function escHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function isGatewayNode(name) {
    return /^(GW[_-]|XLX[_-]|XRF[_-]|REF[_-]|SYSOP[_-])/i.test(name || '');
}

function toQrzCallsign(raw) {
    var s = String(raw || '').trim();
    if (!s) return '';
    s = s.replace(/^\(\d+\)\s*/, '');   // retire le préfixe "(67)"
    s = s.split(/\s+/)[0] || '';        // ne garde que le suffixe "V"
    var dash = s.indexOf('-');
    if (dash !== -1) s = s.substring(0, dash); // retire "-L"/"-R"...
    return s;
}

function formatDuration(seconds) {
    var value = Number(seconds) || 0;
    if (value <= 0) return '&mdash;';
    var minutes   = Math.floor(value / 60);
    var remainder = value % 60;
    return String(minutes).padStart(2, '0') + ':' + String(remainder).padStart(2, '0');
}

function setEl(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val;
}

function setElHTML(id, html) {
    var el = document.getElementById(id);
    if (el) el.innerHTML = html;
}

function setElClassText(id, text, cls) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = text;
    el.className   = cls || '';
}

function setBar(id, pct, extraClass) {
    var el = document.getElementById(id);
    if (!el) return;

    el.style.width = Math.min(100, Math.max(0, pct)) + '%';

    el.classList.remove('progress-ok', 'progress-warning', 'progress-danger');
    if      (extraClass === 'crit') el.classList.add('progress-danger');
    else if (extraClass === 'warn') el.classList.add('progress-warning');
    else                            el.classList.add('progress-ok');
}

// ════════════════════════════════════════════════════════
//  THEME
// ════════════════════════════════════════════════════════

var THEME_KEY = 'svxdash_theme';

function applyTheme(t) {
    document.body.classList.remove('light-mode');
    if (t === 'light') document.body.classList.add('light-mode');
    try { localStorage.setItem(THEME_KEY, t); } catch(e) {}

    var icon = document.querySelector('.theme-icon');
    if (icon) icon.textContent = (t === 'light') ? '🌙' : '☀️';
}

function initTheme() {
    var saved;
    try { saved = localStorage.getItem(THEME_KEY); } catch(e) {}
    applyTheme(saved || CFG.default_theme || 'dark');

    var btn = document.getElementById('theme-toggle');
    if (btn) {
        btn.addEventListener('click', function() {
            applyTheme(document.body.classList.contains('light-mode') ? 'dark' : 'light');
        });
    }
}

window.toggleTheme = function() {
    applyTheme(document.body.classList.contains('light-mode') ? 'dark' : 'light');
};

// ════════════════════════════════════════════════════════
//  FETCH HELPER
// ════════════════════════════════════════════════════════

function fetchJSON(url, callback) {
    var xhr = new XMLHttpRequest();
    var sep = url.indexOf('?') !== -1 ? '&' : '?';
    xhr.open('GET', url + sep + '_=' + Date.now(), true);
    xhr.timeout = 5000;
    xhr.onreadystatechange = function() {
        if (xhr.readyState !== 4 || xhr.status !== 200) return;
        try { callback(JSON.parse(xhr.responseText)); }
        catch(e) { console.warn('[Dashboard] JSON parse error:', e, xhr.responseText.substring(0, 200)); }
    };
    xhr.onerror = xhr.ontimeout = function() {};
    xhr.send();
}

// ════════════════════════════════════════════════════════
//  HARDWARE — refresh every max(10s, refresh×2)
//
//  Targets in index.php:
//    id="live-cpu-temp"    → CPU Temp panel value
//    id="cpu-temp-panel"   → CPU Temp panel (colour class)
//    id="hw-cpu-usage-bar" → CPU usage bar label
//    id="cpu-bar"          → CPU progress bar
//    id="hw-cpu-temp"      → HW Info CPU Temperature
//    id="hw-cpu-cores"     → HW Info CPU Cores
//    id="hw-ram-bar-label" → RAM bar label
//    id="ram-bar"          → RAM progress bar
//    id="hw-disk-bar-label"→ Disk bar label
//    id="disk-bar"         → Disk progress bar
//    id="hw-hostname"      → HW Info Hostname
//    id="hw-local-ip"      → HW Info Local IP
//    id="hw-cpu-arch"      → HW Info Architecture
//    id="hw-kernel"        → HW Info Kernel
//    id="hw-linux"         → HW Info Linux
//    id="hw-svxlink"       → HW Info SvxLink
//    id="hw-uptime"        → HW Info Last Reboot
// ════════════════════════════════════════════════════════

function fetchHardware() {
    fetchJSON(HARDWARE_ENDPOINT, updateHardware);
}

function fetchHardwareLive() {
    fetchJSON(HARDWARE_LIVE_ENDPOINT, function(data) {
        if (!data) return;

        if (data.cpu_temp !== undefined && data.cpu_temp !== '') {
            var temp     = parseFloat(data.cpu_temp);
            var tempCls  = temp >= 70 ? 'val-crit' : (temp >= 55 ? 'val-warn' : 'val-ok');
            var panelCls = temp >= 70 ? 'red'      : (temp >= 55 ? 'amber'    : 'green');
            var tempStr  = data.cpu_temp_display || (data.cpu_temp + '°C');
            setElClassText('live-cpu-temp', tempStr, 'panel-value ' + tempCls);
            var panel = document.getElementById('cpu-temp-panel');
            if (panel) panel.className = 'panel ' + panelCls;
            setElClassText('hw-cpu-temp', tempStr, 'stat-val ' + tempCls);
        }

        if (data.cpu_snapshot) {
            if (_lastCpuSnapshot && data.cpu_snapshot.t > 0 && _lastCpuSnapshot.t > 0) {
                var dt  = data.cpu_snapshot.t - _lastCpuSnapshot.t;
                var di  = data.cpu_snapshot.i - _lastCpuSnapshot.i;
                if (dt > 0) {
                    var cpu    = Math.round((1 - di / dt) * 1000) / 10;
                    cpu        = Math.min(100, Math.max(0, cpu));
                    var cpuCls = cpu >= 85 ? 'val-crit' : (cpu >= 50 ? 'val-warn' : 'val-ok');
                    setElClassText('hw-cpu-usage', cpu + '%', 'stat-val ' + cpuCls);
                    var cpuBarLabel = document.getElementById('hw-cpu-usage-bar');
                    if (cpuBarLabel) cpuBarLabel.textContent = cpu + '%';
                    updateProgressBar('cpu-bar', cpu, 100);
                }
            }
            _lastCpuSnapshot = data.cpu_snapshot;
        }

        if (data.ram) {
            var ramPct      = data.ram.percent || 0;
            var ramBarLabel = document.getElementById('hw-ram-bar-label');
            if (ramBarLabel && data.ram.used && data.ram.total) {
                ramBarLabel.textContent = data.ram.used + ' / ' + data.ram.total;
            }
            updateProgressBar('ram-bar', ramPct, 100);
        }

        if (data.disk) {
            var diskPct      = data.disk.percent || 0;
            var diskBarLabel = document.getElementById('hw-disk-bar-label');
            if (diskBarLabel && data.disk.used && data.disk.total) {
                diskBarLabel.textContent = data.disk.used + ' / ' + data.disk.total;
            }
            updateProgressBar('disk-bar', diskPct, 100);
        }
    });
}

function updateProgressBar(id, value, max) {
    var bar = document.getElementById(id);
    if (!bar) return;
    var pct = Math.min(100, Math.max(0, (value / max) * 100));
    bar.style.width = pct + '%';
    bar.setAttribute('aria-valuenow', value);
    bar.className = 'progress-fill ' +
        (pct > 85 ? 'progress-danger' : pct > 65 ? 'progress-warning' : 'progress-ok');
}

function updateHardware(data) {

    if (data.cpu_temp !== undefined && data.cpu_temp !== '') {
        var temp     = parseFloat(data.cpu_temp);
        var tempCls  = temp >= 70 ? 'val-crit' : (temp >= 55 ? 'val-warn' : 'val-ok');
        var panelCls = temp >= 70 ? 'red'      : (temp >= 55 ? 'amber'    : 'green');
        var tempStr  = data.cpu_temp_display || (data.cpu_temp + '°C');

        setElClassText('live-cpu-temp', tempStr, 'panel-value ' + tempCls);

        var panel = document.getElementById('cpu-temp-panel');
        if (panel) panel.className = 'panel ' + panelCls;

        setElClassText('hw-cpu-temp', tempStr, 'stat-val ' + tempCls);
    }

    if (data.cpu_usage !== undefined) {
        var cpu    = data.cpu_usage;
        var cpuCls = cpu >= 85 ? 'val-crit' : (cpu >= 50 ? 'val-warn' : 'val-ok');
        setElClassText('hw-cpu-usage', cpu + '%', 'stat-val ' + cpuCls);
        var cpuBarLabel = document.getElementById('hw-cpu-usage-bar');
        if (cpuBarLabel) cpuBarLabel.textContent = cpu + '%';
        updateProgressBar('cpu-bar', cpu, 100);
    }

    if (data.cpu_cores !== undefined) {
        setEl('hw-cpu-cores', data.cpu_cores + ' Cores');
    }

    if (data.ram) {
        var ramPct = data.ram.percent || 0;
        setEl('hw-ram-percent', ramPct + '%');
        var ramBarLabel = document.getElementById('hw-ram-bar-label');
        if (ramBarLabel && data.ram.used && data.ram.total) {
            ramBarLabel.textContent = data.ram.used + ' / ' + data.ram.total;
        }
        updateProgressBar('ram-bar', ramPct, 100);
    }

    if (data.disk) {
        var diskPct = data.disk.percent || 0;
        setEl('hw-disk-percent', diskPct + '%');
        var diskBarLabel = document.getElementById('hw-disk-bar-label');
        if (diskBarLabel && data.disk.used && data.disk.total) {
            diskBarLabel.textContent = data.disk.used + ' / ' + data.disk.total;
        }
        updateProgressBar('disk-bar', diskPct, 100);
    }

    if (data.hostname)        setEl('hw-hostname', data.hostname);
    if (data.local_ip)        setEl('hw-local-ip', data.local_ip);
    if (data.cpu_arch)        setEl('hw-cpu-arch', data.cpu_arch);
    if (data.kernel)          setEl('hw-kernel',   data.kernel);
    if (data.linux_version)   setEl('hw-linux',    data.linux_version);
    if (data.svxlink_version) setEl('hw-svxlink',  data.svxlink_version);
    if (data.system_uptime)   setEl('hw-uptime',   data.system_uptime);
    
}

// ════════════════════════════════════════════════════════
//  UPTIME SYSTÈME — timer local incrémental
//  Récupère la valeur initiale depuis PHP via AJAX une fois,
//  puis incrémente toutes les secondes en JS pur.
// ════════════════════════════════════════════════════════

var _sysUptimeSeconds = 0;  
var _sysUptimeTimer   = null;

function parseSysUptime(str) {
    var seconds = 0;
    var d = str.match(/(\d+)d/);
    var h = str.match(/(\d+)h/);
    var m = str.match(/(\d+)m/);
    if (d) seconds += parseInt(d[1]) * 86400;
    if (h) seconds += parseInt(h[1]) * 3600;
    if (m) seconds += parseInt(m[1]) * 60;
    return seconds;
}

function formatSysUptime(seconds) {
    var d = Math.floor(seconds / 86400);
    var h = Math.floor((seconds % 86400) / 3600);
    var m = Math.floor((seconds % 3600) / 60);
    var parts = [];
    if (d > 0) parts.push(d + 'd');
    if (h > 0) parts.push(h + 'h');
    parts.push(m + 'm');
    return parts.join(' ');
}

// ════════════════════════════════════════════════════════
//  ECHOLINK
//
//  Targets in index.php:
//    id="el-callsign" → QRZ callsign link
//    id="el-location" → location
//    id="el-sysop"    → sysop name
//    id="el-nodes"    → connected nodes (QRZ links)
//    id="el-count"    → connection count
// ════════════════════════════════════════════════════════

function fetchEcholink() {
    fetchJSON(ECHOLINK_ENDPOINT, updateEcholinkPanel);
}

function renderQrzLink(callsign, qrzCallsign) {
    if (!callsign) return '<span class="no-data">—</span>';
    if (CFG.qrz_enabled && !isGatewayNode(callsign)) {
        var qrz = toQrzCallsign(qrzCallsign || callsign);
        return '<a href="' + CFG.qrz_url + encodeURIComponent(qrz)
             + '" target="_blank" rel="noopener" class="callsign-link">'
             + escHtml(callsign.replace(/0/g, '\u00d8')) + '</a>';
    }
    return escHtml(callsign);
}

function renderNodesList(nodes) {
    if (!Array.isArray(nodes) || nodes.length === 0) {
        return 'NO NODE CONNECTED';
    }
    return nodes.map(function(node) {
        var base = toQrzCallsign(node);
        if (CFG.qrz_enabled && !isGatewayNode(node)) {
            return '<a href="' + CFG.qrz_url + encodeURIComponent(base)
                 + '" target="_blank" rel="noopener" class="callsign-link">'
                 + escHtml(node.replace(/0/g, '\u00d8')) + '</a>';
        }
        return escHtml(node);
    }).join(' , ');
}

function updateEcholinkPanel(data) {
    if (!data) return;

    var csEl = document.getElementById('el-callsign');
    if (csEl) csEl.innerHTML = renderQrzLink(data.callsign || '', data.callsign_qrz || '');

    setEl('el-location', data.location || '—');
    setEl('el-sysop',    data.sysop    || '—');
    setElHTML('el-nodes', renderNodesList(data.connected_nodes || []));
    setEl('el-count', String(data.connected_count || 0));
}

// ════════════════════════════════════════════════════════
//  CONNECTED NODES (nodes.php only)
// ════════════════════════════════════════════════════════

function fetchNodes() {
    fetchJSON(NODES_ENDPOINT, updateNodesPanel);
}

function updateNodesPanel(data) {
    var container = document.getElementById('nodes-live');
    if (!container || !data) return;

    var nodes = Array.isArray(data.nodes) ? data.nodes : [];

    setEl('nodes-count', String(data.count || nodes.length || 0));

    if (nodes.length === 0) {
        container.innerHTML = '<span class="module-empty">No nodes connected</span>';
        return;
    }

    container.innerHTML = nodes.map(function(node) {
        var cls = 'node-badge' + (node.transmitting ? ' transmitting' : '');
        return '<span class="' + cls + '">' + escHtml(node.callsign) + '</span>';
    }).join('');
}

// ════════════════════════════════════════════════════════
//  SVXLINK STATUS (global polling)
// ════════════════════════════════════════════════════════

function fetchStatus() {
    fetchJSON(JSON_ENDPOINT, updateStatus);
}

function updateStatus(data) {
    setEl('svx-uptime', data.svx_uptime || '--');

    var ctcssRow = document.getElementById('ctcss-row');
    if (ctcssRow) {
        if (data.ctcss && data.ctcss !== '') {
            ctcssRow.style.display = '';
            setEl('ctcss-val', data.ctcss + ' Hz');
        } else {
            ctcssRow.style.display = 'none';
        }
    }

    if (data.modules)          updateModules(data.modules);
    if (data.repeater_runtime) updateRepeaterState(data.repeater_runtime);

    if (data.reflector_activity) updateReflectorActivity(data.reflector_activity);
    if (data.tg_info)            updateTgNodeList(data.tg_info);
}

// ════════════════════════════════════════════════════════
//  TG NODE-LIST — Reflector & Talk Groups panel
//  Targets: #tg-default .node-ping
//           #tg-monitor .node-ping
//           #tg-active  .node-ping
// ════════════════════════════════════════════════════════

function updateTgNodeList(tg) {
    if (!tg) return;

    var defEl = document.querySelector('#tg-default .node-ping');
    if (defEl) defEl.textContent = tg.default || 'Not Defined';

    var monEl = document.querySelector('#tg-monitor .node-ping');
    if (monEl) {
        var monText = tg.monitor && tg.monitor.length
            ? tg.monitor.join(', ')
            : 'No monitored TG';
        if (tg.tmp) monText += ' [tmp: ' + tg.tmp + ']';
        monEl.textContent = monText;
    }

    var actEl = document.querySelector('#tg-active .node-ping');
    if (actEl) actEl.textContent = (tg.selected && tg.selected !== '0') ? tg.selected : 'No Active TG';
}

// ════════════════════════════════════════════════════════
//  MODULES
// ════════════════════════════════════════════════════════

function updateModules(modules) {
    var el = document.getElementById('modules-live');
    if (!el) return;
    if (!modules.length) {
        el.innerHTML = '<span class="no-data">Aucun module actif</span>';
        return;
    }
    el.innerHTML = modules.map(function(m) {
        return '<span class="module-badge">' + escHtml(m) + '</span>';
    }).join('');
}


// ════════════════════════════════════════════════════════
//  SVXREFLECTOR
// ════════════════════════════════════════════════════════

function updateRepeaterState(state) {
    if (!state || !state.status) return;
    updateRepeaterUI(state);
}

function updateRxTx(type) {
    if (!type) return;
    var statusMap = { tx: 'tx', rx: 'rx', listening: 'listening' };
    var mapped = statusMap[type] || 'listening';
    updateRepeaterUI({ status: mapped, description: '' });
}

function renderReflectorCallsign(entry) {
    var callsign = entry.callsign || '';
    if (!callsign) return '<span class="no-data">&mdash;</span>';
    if (CFG.qrz_enabled && !isGatewayNode(callsign)) {
        return '<a href="' + CFG.qrz_url + encodeURIComponent(toQrzCallsign(entry.callsign_qrz || callsign))
             + '" target="_blank" rel="noopener" class="callsign-link">' + escHtml(callsign) + '</a>';
    }
    var cls = entry.is_gateway ? 'gateway-name' : '';
    return '<span class="' + cls + '">' + escHtml(callsign) + '</span>';
}

function updateReflectorActivity(entries) {
    var body = document.getElementById('reflector-activity-body');
    if (!body) return;

    if (!Array.isArray(entries) || entries.length === 0) {
        body.innerHTML = '<tr><td colspan="5" class="card-empty">No activity found in the log.</td></tr>';
        return;
    }

    
    var seen    = {};
    var deduped = [];

    entries.forEach(function(entry) {
        var cs = (entry.callsign || '').toUpperCase();
        if (!cs) {
            deduped.push(entry);
            return;
        }
        if (seen[cs] === undefined) {
            seen[cs] = deduped.length;
            deduped.push(entry);
        } else if (entry.active && !deduped[seen[cs]].active) {
            deduped[seen[cs]] = entry;
        }
    });

    deduped.sort(function(a, b) {
        if (a.active && !b.active) return -1;
        if (!a.active && b.active) return  1;
        return (Number(b.start_timestamp) || 0) - (Number(a.start_timestamp) || 0);
    });

    var orphans = body.querySelectorAll('tr:not([data-cs])');
    orphans.forEach(function(tr) { tr.parentNode.removeChild(tr); });

    var existingRows = {};
    var allTr = body.querySelectorAll('tr[data-cs]');
    allTr.forEach(function(tr) { existingRows[tr.getAttribute('data-cs')] = tr; });

    var newKeys = {};

    deduped.forEach(function(entry, idx) {
        var cs     = (entry.callsign || '').toUpperCase() || ('__nocs__' + idx);
        var tg     = entry.tg      ? escHtml(entry.tg)      : '<span class="no-data">&mdash;</span>';
        var tgName = entry.tg_name ? escHtml(entry.tg_name) : '<span class="no-data">&mdash;</span>';
        var dot    = entry.active  ? '<span class="tx-indicator-dot" title="In transmission"></span>' : '';
        var durTs  = escHtml(String(entry.start_timestamp || 0));
        var durAct = entry.active ? '1' : '0';
        var dur    = formatDuration(entry.duration);

        newKeys[cs] = true;

        var tr = existingRows[cs];
        if (!tr) {
            tr = document.createElement('tr');
            tr.setAttribute('data-cs', cs);
            tr.innerHTML =
                  '<td class="rf-td-time"></td>'
                + '<td class="rf-td-cs"></td>'
                + '<td class="rf-td-tg"></td>'
                + '<td class="rf-td-name"></td>'
                + '<td class="rf-td-dur"></td>';
            body.appendChild(tr);
            existingRows[cs] = tr;
        }

        tr.className = entry.active ? 'rf-row-tx' : '';

        var cells   = tr.cells;
        var timeHTML = dot + escHtml(entry.datetime || '');
        var csHTML   = renderReflectorCallsign(entry);

        if (cells[0].innerHTML !== timeHTML) cells[0].innerHTML = timeHTML;
        if (cells[1].innerHTML !== csHTML)   cells[1].innerHTML = csHTML;
        if (cells[2].innerHTML !== tg)       cells[2].innerHTML = tg;
        if (cells[3].innerHTML !== tgName)   cells[3].innerHTML = tgName;

        var durCell = cells[4];
        if (durCell.getAttribute('data-start-ts') !== durTs)  durCell.setAttribute('data-start-ts', durTs);
        if (durCell.getAttribute('data-active')   !== durAct) durCell.setAttribute('data-active',   durAct);
        if (!entry.active && durCell.innerHTML !== dur) durCell.innerHTML = dur;
    });

    Object.keys(existingRows).forEach(function(cs) {
        if (!newKeys[cs]) {
            var tr = existingRows[cs];
            if (tr && tr.parentNode) tr.parentNode.removeChild(tr);
        }
    });

    deduped.forEach(function(entry, idx) {
        var cs = (entry.callsign || '').toUpperCase() || ('__nocs__' + idx);
        var tr = existingRows[cs];
        if (!tr) return;
        var current = body.rows[idx];
        if (current !== tr) body.insertBefore(tr, current || null);
    });
}

function tickActiveDurations() {
    var body = document.getElementById('reflector-activity-body');
    if (!body) return;

    var cells = body.querySelectorAll('.rf-td-dur[data-active="1"]');
    var now   = Math.floor(Date.now() / 1000);

    cells.forEach(function(cell) {
        var startTs = Number(cell.getAttribute('data-start-ts')) || 0;
        if (startTs <= 0) return;
        cell.innerHTML = formatDuration(Math.max(0, now - startTs));
    });
}

// ════════════════════════════════════════════════════════
//  UPTIME SVXLINK — refresh every 30 s
//  Target: id="svx-uptime"
// ════════════════════════════════════════════════════════

function fetchUptime() {
    fetchJSON(UPTIME_ENDPOINT, function(data) {
        if (!data) return;

        var uptimeEl = document.getElementById('svx-uptime');
        if (uptimeEl && data.uptime !== undefined) {
            uptimeEl.textContent = data.uptime !== '' ? data.uptime : '--';
        }

        var dot   = document.getElementById('rxdot');
        var label = document.getElementById('statusLabel');
        var text  = document.getElementById('statusText');

        if (dot && label && text && data.status) {
            if (data.status === 'active') {
                dot.className     = 'status-dot';
                label.className   = 'status-label active';
                text.textContent  = 'ACTIVE';
            } else if (data.status === 'inactive') {
                dot.className     = 'status-dot off';
                label.className   = 'status-label inactive';
                text.textContent  = 'STOPPED';
            } else if (data.status === 'idle') {
                dot.className     = 'status-dot idle';
                label.className   = 'status-label failed';
                text.textContent  = 'IDLE';
            } else {
                dot.className     = 'status-dot off';
                label.className   = 'status-label';
                text.textContent  = 'UNKNOWN';
            }
        }
    });
}

// ════════════════════════════════════════════════════════
//  UPTIME SYSTÈME — ticker local incrémental (suite)
// ════════════════════════════════════════════════════════

function startSysUptimeTicker(initialStr) {
    _sysUptimeSeconds = parseSysUptime(initialStr || '0m');
    if (_sysUptimeTimer) clearInterval(_sysUptimeTimer);
    _sysUptimeTimer = setInterval(function() {
        _sysUptimeSeconds++;
        var el = document.getElementById('hw-uptime');
        if (el) el.textContent = formatSysUptime(_sysUptimeSeconds);
    }, 1000);
}

// ════════════════════════════════════════════════════════
//  REPEATER STATUS (TX / RX / LISTENING) — fast polling
// ════════════════════════════════════════════════════════

function fetchRepeaterStatus() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', REPEATER_STATUS_ENDPOINT + '&_=' + Date.now(), true);
    xhr.timeout = 2000;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try { updateRepeaterUI(JSON.parse(xhr.responseText)); }
            catch(e) { /* silent */ }
        }
    };
    xhr.send();
}

function updateRepeaterUI(data) {
    if (!data || !data.status) return;

    var rsMain  = document.getElementById('rs-main');
    var rsText  = document.getElementById('rs-text');
    var rsDesc  = document.getElementById('rs-desc');
    var rsDot   = document.getElementById('rs-dot');
    var rsPanel = document.querySelector('.repeater-status-panel'); // ← ajouter

    if (!rsMain || !rsText) return;

    if (data.status === currentRepeaterStatus) {
        if ((data.status === 'tx' || data.status === 'rx') && !rsMain.hasAttribute('data-animating')) {
            rsMain.setAttribute('data-animating', 'true');
            rsMain.style.animation = 'pulse 0.3s ease-in-out 2';
            setTimeout(function() {
                if (rsMain) { rsMain.style.animation = ''; rsMain.removeAttribute('data-animating'); }
            }, 600);
        }
        return;
    }

    currentRepeaterStatus = data.status;
    rsMain.className = 'rs-state active ' + data.status;

    if (data.status === 'tx') {
    rsText.textContent = 'TX';
    if (rsDesc)  rsDesc.textContent = data.description || '';
    if (rsDot)   rsDot.style.display = 'inline-block';
    if (rsPanel) rsPanel.className = 'repeater-status-panel red';
} else if (data.status === 'rx') {
    rsText.textContent = 'RX';
    if (rsDesc)  rsDesc.textContent = data.description || '';
    if (rsDot)   rsDot.style.display = 'inline-block';
    if (rsPanel) rsPanel.className = 'repeater-status-panel green';
} else {
    rsText.textContent = 'LISTENING';
    if (rsDesc)  rsDesc.textContent = data.description || '';
    if (rsDot)   rsDot.style.display = 'none';
    if (rsPanel) rsPanel.className = 'repeater-status-panel';
}

    rsMain.style.animation = 'pulse 0.5s ease-in-out 3';
    setTimeout(function() { if (rsMain) rsMain.style.animation = ''; }, 1500);
}

// ════════════════════════════════════════════════════════
//  INIT / POLLING
// ════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {

    initTheme();

    startClock();
    startRealTimeClock();

    fetchHardware();
    fetchHardwareLive();
    fetchEcholink();
    fetchUptime();

    var hasNodesPanel = !!document.getElementById('nodes-live');

    if (hasNodesPanel) {
        fetchNodes();
        setInterval(fetchNodes, 1000);
    }

    var initUptime = document.getElementById('hw-uptime');
    if (initUptime && initUptime.textContent.trim() !== '') {
        startSysUptimeTicker(initUptime.textContent.trim());
    }

    setInterval(fetchStatus, CFG.refresh * 1000);

    setInterval(fetchHardwareLive, CFG.refresh * 1000);

    setInterval(fetchEcholink, 10 * 1000);

    setInterval(fetchUptime, 10 * 1000);

    if (!repeaterFastPollTimer) {
        repeaterFastPollTimer = setInterval(function() {
            fetchJSON(JSON_ENDPOINT, function(data) {
                if (data.repeater_runtime)    updateRepeaterState(data.repeater_runtime);
                if (data.reflector_activity)  updateReflectorActivity(data.reflector_activity);
            });
            fetchRepeaterStatus();
            tickActiveDurations();
        }, 500);
    }
});