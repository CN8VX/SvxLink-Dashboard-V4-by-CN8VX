<?php
/**
 * nodes.php — SvxLink Dashboard by CN8VX © 2026
 * Connected Nodes display page.
 * Any modification must retain the CN8VX designation and the corresponding version number.
 */
require_once __DIR__ . '/include/infosvx.php';

$hasLogo = (LOGO_PATH !== '' && file_exists(__DIR__ . '/' . LOGO_PATH));

$connectedNodes = getSVXReflectorNodes();
$activeTalkers  = getActiveTalkerCallsigns();
$totalNodes     = count($connectedNodes);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connected Nodes - SvxLink <?php echo htmlspecialchars($repeaterType ?? ''); ?> Repeater Dashboard - <?php echo htmlspecialchars($CALLSIGN); ?></title>
    <link rel="shortcut icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/style.css">
    <script src="scripts/main.js"></script>
</head>
<body>
<?php $activeNav = 'nodes'; include __DIR__ . '/include/navbar.php'; ?>
<?php include __DIR__ . '/include/header.php'; ?>

<div id="root" class="dark-bg">
    <div style="right: 0; important;"></div>
    <h1 class="tg-title">🌐 Connected Nodes to SVXReflector</h1>

    <div class="tg-header-stats">
    <div class="tg-stat-card">
        <div class="tg-stat-info">
            <div class="tg-stat-label">Total nodes connected</div>
            <div class="tg-stat-value tg-total" id="nodes-count"><?php echo $totalNodes; ?></div>
            <!-- <span class="tg-stat-value tg-active"><?php echo htmlspecialchars($tgselect ?: 'No Active TG'); ?></span> -->
        </div>
    </div>
    </div>

    <div class="module-panel" >
        <div class="panel-label panel-bar"><span class="block-icon">🌐</span>Connected Nodes to SVXReflector</div>
        <div class="module-list module-connected" id="nodes-live" >
            <?php if (!empty($connectedNodes)): ?>
                <?php foreach ($connectedNodes as $node): ?>
                    <span class="node-badge<?php echo in_array($node, $activeTalkers, true) ? ' transmitting' : ''; ?>">
                        <?php echo htmlspecialchars($node); ?>
                    </span>
                <?php endforeach; ?>
            <?php else: ?>
                <span class="module-empty">No nodes connected</span>
            <?php endif; ?>
        </div>
    </div></div>
    </div>

<?php include __DIR__ . '/include/footer.php'; ?>
</body>
</html>
