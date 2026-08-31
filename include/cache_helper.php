<?php
/**
 * cache_helper.php — SvxLink Dashboard by CN8VX © 2026
 *
 * Cache fichier simple avec TTL et protection anti "cache stampede".
 * Objectif : quand plusieurs onglets/clients ont le dashboard ouvert
 * en même temps, les opérations coûteuses (lecture de logs, appels
 * systemctl...) ne sont recalculées qu'une seule fois par fenêtre de
 * temps (TTL), au lieu d'une fois PAR requête PHP PAR client.
 *
 * Le cache est partagé entre TOUS les processus PHP (tous les workers
 * Apache/PHP-FPM, tous les clients) car stocké sur disque.
 */

if (!defined('DASHBOARD_CACHE_DIR')) {
    define('DASHBOARD_CACHE_DIR', sys_get_temp_dir() . '/svxdash_cache');
}

/**
 * Retourne le résultat de $producer(), mais au maximum une fois toutes
 * les $ttl secondes. Entre-temps, sert le résultat déjà écrit sur disque.
 */
function dashboard_cached(string $key, int $ttl, callable $producer) {
    if (!is_dir(DASHBOARD_CACHE_DIR)) {
        @mkdir(DASHBOARD_CACHE_DIR, 0700, true);
    }

    $safeKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
    $file     = DASHBOARD_CACHE_DIR . '/' . $safeKey . '.cache';
    $lockFile = DASHBOARD_CACHE_DIR . '/' . $safeKey . '.lock';

    // 1) Cache encore frais ? -> on ne touche à rien d'autre.
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $decoded = @json_decode((string) file_get_contents($file), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }

    // 2) Cache expiré ou absent. Un seul processus le régénère (flock),
    //    les autres reçoivent l'ancien résultat au lieu d'empiler
    //    d'autres appels coûteux en parallèle.
    $fp = @fopen($lockFile, 'c');
    if ($fp && flock($fp, LOCK_EX | LOCK_NB)) {
        try {
            $result = $producer();
            @file_put_contents(
                $file,
                json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                LOCK_EX
            );
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $result;
    }
    if ($fp) {
        fclose($fp);
    }

    // 3) Un autre processus est déjà en train de régénérer -> on sert
    //    ce qu'il y a (même un peu périmé) plutôt que d'attendre.
    if (is_file($file)) {
        $decoded = @json_decode((string) file_get_contents($file), true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
    }

    // 4) Cold start, aucun cache disponible — on calcule directement.
    return $producer();
}
