#!/usr/bin/env php
<?php
/**
 * cron/update_nostr_cache.php
 * Met à jour cached_name, cached_avatar, cached_bio, cached_nip05
 * pour tous les profils actifs dont les métadonnées ou les stats sont absentes
 * ou datent de plus de 6h.
 *
 * Source : cache2.primal.net (accessible depuis le serveur)
 * Note : followers/posts sont mis à jour côté navigateur via nostr-cache.php
 *
 * Lancer via cron :
 *   0 * * * * docker exec nostrmap_php php /var/www/cron/update_nostr_cache.php >> /var/log/nostrmap_cache.log 2>&1
 */

declare(strict_types=1);
require_once '/var/www/config/db.php';
require_once '/var/www/html/api/_helpers.php';

$batchSize = (int)(getenv('CRON_CACHE_BATCH') ?: 20);
$forceAll  = (bool)(int)(getenv('CRON_CACHE_FORCE_ALL') ?: 0);
$db        = getDB();

// Profils à rafraîchir :
// - jamais fetchés (last_fetch IS NULL)
// - profils sans stats (0/0) : toutes les 2h (Primal indexe lentement les nouveaux)
// - profils avec stats réelles : toutes les 6h
if ($forceAll) {
    $stmt = $db->prepare(
        "SELECT npub FROM profiles
         WHERE status = 'active'
         ORDER BY COALESCE(last_stats_fetch, last_fetch, registered_at) ASC
         LIMIT ?"
    );
    $stmt->execute([$batchSize]);
} else {
    $stmt = $db->prepare(
        "SELECT npub FROM profiles
         WHERE status = 'active'
           AND (
               last_fetch IS NULL
               OR (
                   nostr_followers = 0 AND nostr_posts = 0
                   AND last_fetch < DATE_SUB(NOW(), INTERVAL 2 HOUR)
               )
               OR (
                   (nostr_followers > 0 OR nostr_posts > 0)
                   AND last_fetch < DATE_SUB(NOW(), INTERVAL 6 HOUR)
               )
           )
         ORDER BY COALESCE(last_stats_fetch, last_fetch, registered_at) ASC
         LIMIT ?"
    );
    $stmt->execute([$batchSize]);
}
$profiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($profiles)) {
    echo "[update_nostr_cache] Aucun profil à rafraîchir.\n";
    exit(0);
}

echo "[update_nostr_cache] " . count($profiles) . " profil(s) à traiter...\n";

$updated = 0;
$errors  = 0;

foreach ($profiles as $npub) {
    try {
        $hex = npubToHex($npub);
    } catch (Exception $e) {
        echo "  SKIP (npub invalide): {$npub}\n";
        $errors++;
        continue;
    }

    // Vérifier si le nom a été verrouillé par l'utilisateur
    $lockRow = $db->prepare('SELECT display_name_updated_at FROM profiles WHERE npub = ?');
    $lockRow->execute([$npub]);
    $nameLocked = (bool) $lockRow->fetchColumn();

    // 1. Primal : métadonnées + stats en un seul appel
    [$meta, $stats] = fetchPrimalData($hex);

    // 2. Fallback : cache relay (alimenté par relay_meta_host.py sur le host)
    // Si cache vide aussi, mise en queue pour le prochain passage de relay_meta_host.py
    if (!$meta) {
        $meta = fetchRelayMeta($hex);
        if (!$meta) queueRelayFetch($hex);
    }

    // Mettre à jour last_fetch dans tous les cas
    $set    = ['last_fetch = NOW()'];
    $params = [];

    if ($meta) {
        applyMetaToSet($meta, $nameLocked, $set, $params);
    }

    if ($stats) {
        // Toujours écrire les stats quand Primal répond — y compris les baisses vers 0
        if (!empty($stats['joined_at'])) { $set[] = 'nostr_created_at = ?'; $params[] = $stats['joined_at']; }
        $set[] = 'nostr_followers = ?';  $params[] = $stats['followers'];
        $set[] = 'nostr_posts = ?';      $params[] = $stats['posts'];
        $set[] = 'last_stats_fetch = NOW()';
    }

    $params[] = $npub;
    $db->prepare('UPDATE profiles SET ' . implode(', ', $set) . ' WHERE npub = ?')
       ->execute($params);

    $hasRealStats = $stats && ($stats['followers'] > 0 || $stats['posts'] > 0);
    if ($meta || $hasRealStats) {
        $updated++;
        $src = $meta ? 'Primal' : 'relay';
        echo "  OK [{$src}]: @" . ($meta['name'] ?? $npub)
            . ($stats ? " (followers:{$stats['followers']} posts:{$stats['posts']})" : '') . "\n";
    } else {
        echo "  WAIT (pas encore indexé): {$npub}\n";
    }

    usleep(300_000); // 0.3s entre chaque requête
}

echo "[update_nostr_cache] Terminé : {$updated} mis à jour, {$errors} erreurs — " . date('Y-m-d H:i:s') . "\n";
// fetchPrimalData, npubToHex : fournis par _helpers.php (require_once ci-dessus)
