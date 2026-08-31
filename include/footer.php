<?php
/**
 * SvxLink Dashboard by CN8VX © 2026
 * Any modification of the "FOOTER" must include the "CN8VX" designators, as well as the corresponding version.
 * Toute modification du "FOOTER" doit obligatoirement inclure l'indicatif "CN8VX", ainsi que la version correspondante.
*/
require_once __DIR__ . '/config.php';
?>
<footer>
<!-- Credits: CN8VX 2026. -->
<br><br>
    <div class="fixed-footer">
        <p>SYSOP of Repeater : <a href="https://www.qrz.com/db/<?php echo $SYSOP; ?>" target="_blank"><?php echo $SYSOP; ?></a> <?php echo $SYSOPNAME; ?> | 
         SvxLink Dashboard Repeater V2 Developed by <a target="_blank" href="https://github.com/CN8VX">CN8VX</a>. Version <b>4.1</b> © <?php echo date("Y"); ?> All rights reserved.</p>
    </div>
</footer>
