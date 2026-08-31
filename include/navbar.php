<?php
/**
 * navbar.php by CN8VX © 2026
 * Barre de navigation.
 *
 * Usage dans index.php :
 *   <?php include __DIR__ . '/include/navbar.php'; ?>
 *
 * Pour marquer une page active, définir avant l'include :
 *   $activeNav = 'dashboard'; // 'dashboard' | 'activity' | 'talkgroups' | 'echolink'
 */

$activeNav = $activeNav ?? 'dashboard';
?>
<nav class="dash-nav">
    <div class="nav-inner">
        <ul class="nav-menu">

            <li>
                <a href="index.php"
                   class="nav-link<?php echo $activeNav === 'dashboard' ? ' active' : ''; ?>">
                    📡 Dashboard
                </a>
            </li>

            <li>
                <a href="nodes.php" target="_blank"
                   class="nav-link<?php echo $activeNav === 'nodes' ? ' active' : ''; ?>">
                    🌐 Nodes
                </a>
            </li>

            <li>
                <a href="talkgroup.php" target="_blank" 
                   class="nav-link<?php echo $activeNav === 'talkgroups' ? ' active' : ''; ?>">
                    🔊 Talk Groups
                </a>
            </li>

            <li>
                <a href="logsvx.php" target="_blank" rel="noopener noreferrer"
                   class="nav-link<?php echo $activeNav === 'activity' ? ' active' : ''; ?>">
                    📋 Logs
                </a>
            </li>

            <?php if (!empty($elModActive)): ?>
            <li>
                <a href="echolinksvx/index.php" target="_blank" rel="noopener noreferrer"
                   class="nav-link<?php echo $activeNav === 'echolink' ? ' active' : ''; ?>">
                    🔗 EchoLink
                </a>
            </li>
            <?php endif; ?>

            <!-- =================================================================
                    Liens externes — modifier selon votre installation
                    External links — customize according to your installation
            ====================================================================== --> 
            
            <li>
                <a href="http://refsvx.dmr-maroc.com/" target="_blank" rel="noopener"
                   class="nav-link nav-link-ext">SVXReflector</a>
            </li>

            <li>
                <a href="http://free.dmr-maroc.com/allmon3/" target="_blank" rel="noopener"
                   class="nav-link nav-link-ext">AllStarLink HUB</a>
            </li>

            <li>
                <a href="http://6041.adn.systems/" target="_blank" rel="noopener"
                   class="nav-link nav-link-ext">ADN MAROC</a>
            </li>

        </ul>
    </div>
</nav>