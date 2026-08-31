<?php
/**
 * SvxLink Dashboard by CN8VX © 2026
 * Talk Groups display page
 *
 * Les données sont lues directement depuis include/talkgroups.json
 * Data is read directly from include/talkgroups.json
 *
 * Toute modification de ce fichier est automatiquement reflétée au rechargement de la page
 * Any changes made to this file are automatically reflected when the page is refreshed
*/
require_once __DIR__ . '/include/infosvx.php';
require_once __DIR__ . '/include/hardware_info.php';

$hw             = getAllHardwareInfo();
$repeaterData   = getRepeaterStatus();
$repeaterStatus = $repeaterData['status'];
$rsDesc         = $repeaterData['description'];
$hasLogo        = (LOGO_PATH !== '' && file_exists(__DIR__ . '/' . LOGO_PATH));

$talkgroupsJsonPath = __DIR__ . '/include/talkgroups.json';
$talkgroups = [];
if (is_readable($talkgroupsJsonPath)) {
    $decoded = json_decode((string) file_get_contents($talkgroupsJsonPath), true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $talkgroups = $decoded;
    }
}

ksort($talkgroups, SORT_NUMERIC);

$totalTG = count($talkgroups);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Talk Groups - SvxLink <?php echo htmlspecialchars($repeaterType ?? ''); ?> Repeater Dashboard - <?php echo htmlspecialchars($CALLSIGN); ?></title>
    <link rel="shortcut icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
    <script src="scripts/main.js"></script>
</head>
<body>
<?php $activeNav = 'talkgroups'; include __DIR__ . '/include/navbar.php'; ?>   
<?php include __DIR__ . '/include/header.php'; ?>

<div id="root" class="dark-bg">
    <div style="right: 0; important;"></div>
    <h1 class="tg-title">🔊 Talk Groups</h1>
    
    <div class="tg-header-stats">
        <div class="tg-stat-card">
            <div class="tg-stat-info">
                <div class="tg-stat-label">Total Talk Groups</div>
                <div class="tg-stat-value tg-total"><?php echo $totalTG; ?></div>
            </div>
        </div>
        
        <div class="tg-stat-card">
            <div class="tg-stat-info">
                <div class="tg-stat-label">Last Active Talk Group</div>
                <span class="tg-stat-value tg-active"><?php echo htmlspecialchars($tgselect ?: 'No Active TG'); ?></span>
            </div>
        </div>
    </div>
    
    <div class="tg-search-bar">
        <input type="text" id="tg-search" class="tg-search-input" placeholder="🔍 Search by TG number or name...">
    </div>
    
    <div class="tg-table-container">
        <table class="tg-table" id="tg-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>TG Number</th>
                    <th>Talk Group Name</th>
                </tr>
            </thead>
            <tbody id="tg-table-body">
                <?php 
                $index = 1;
                if (!empty($talkgroups)):
                    foreach ($talkgroups as $number => $name): 
                ?>
                <tr data-tg-number="<?php echo htmlspecialchars($number); ?>" data-tg-name="<?php echo htmlspecialchars($name); ?>">
                    <td><?php echo $index++; ?></td>
                    <td class="tg-number"><?php echo htmlspecialchars($number); ?></td>
                    <td class="tg-name"><?php echo htmlspecialchars($name); ?></td>
                </tr>
                <?php 
                    endforeach;
                else:
                ?>
                <tr class="empty-row">
                    <td colspan="3" class="tg-empty">
                        ⚠️ No Talk Group defined in <code class=cde>talkgroups.json</code>
                        <br><br>
                        <small>Format: <code>"TG_NUMBER": "Talk Group Name"</code></small>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="tg-note">
        💡Any changes made to the <code class=cde>talkgroups.json</code> 
        file will be automatically reflected in this table. Simply refresh this page by pressing the <code class=cde>F5</code> key.
    </div>
</div> 
</div>


<?php include __DIR__ . '/include/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('tg-search');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const tbody = document.getElementById('tg-table-body');
            const rows = tbody.querySelectorAll('tr');
            
            let visibleCount = 0;
            
            rows.forEach(function(row) {
                if (row.classList.contains('empty-row')) {
                    return;
                }
                
                const tgNumber = row.getAttribute('data-tg-number') || '';
                const tgName = row.getAttribute('data-tg-name') || '';
                
                const matches = searchTerm === '' || 
                               tgNumber.toLowerCase().includes(searchTerm) || 
                               tgName.toLowerCase().includes(searchTerm);
                
                row.style.display = matches ? '' : 'none';
                if (matches) visibleCount++;
            });
            
            const oldNoResult = tbody.querySelector('.no-results-row');
            if (oldNoResult) {
                oldNoResult.remove();
            }
            
            if (visibleCount === 0 && searchTerm !== '') {
                const noResultRow = document.createElement('tr');
                noResultRow.className = 'no-results-row';
                noResultRow.innerHTML = '<td colspan="3" class="no-results">🔍 No Talk Groups found.</td>';
                tbody.appendChild(noResultRow);
            }
        });
    }
});
</script>

</body>
</html>