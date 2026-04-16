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

// Profils à rafraîchir : jamais mis à jour ou > 6h
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
               OR last_fetch < DATE_SUB(NOW(), INTERVAL 6 HOUR)
               OR last_stats_fetch IS NULL
               OR last_stats_fetch < DATE_SUB(NOW(), INTERVAL 6 HOUR)
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

    // Métadonnées + stats depuis Primal (un seul appel)
    [$meta, $stats] = fetchPrimalData($hex);

    // Mettre à jour last_fetch dans tous les cas (même si pas de données)
    $set    = ['last_fetch = NOW()'];
    $params = [];

    if ($meta) {
        // Ne pas écraser le nom si l'utilisateur l'a défini manuellement
        if (!empty($meta['name']) && !$nameLocked) { array_unshift($set, 'cached_name = ?');   array_unshift($params, mb_substr($meta['name'],       0, 100)); }
        if (!empty($meta['nostr_name']))            { array_unshift($set, 'nostr_name = ?');    array_unshift($params, mb_substr($meta['nostr_name'], 0, 100)); }
        if (!empty($meta['picture']))               { array_unshift($set, 'cached_avatar = ?'); array_unshift($params, mb_substr($meta['picture'],    0, 500)); }
        if (!empty($meta['about']))                 { array_unshift($set, 'cached_bio = ?');    array_unshift($params, mb_substr($meta['about'],      0, 2000)); }
        if (!empty($meta['nip05']))                 { array_unshift($set, 'cached_nip05 = ?');  array_unshift($params, mb_substr($meta['nip05'],      0, 200)); }
    }

    if ($stats) {
        if (!empty($stats['joined_at'])) { $set[] = 'nostr_created_at = ?'; $params[] = $stats['joined_at']; }
        if ($stats['followers'] > 0) { $set[] = 'nostr_followers = ?'; $params[] = $stats['followers']; }
        if ($stats['posts']     > 0) { $set[] = 'nostr_posts = ?';     $params[] = $stats['posts']; }
        $set[] = 'last_stats_fetch = NOW()';
    }

    $params[] = $npub;
    $db->prepare('UPDATE profiles SET ' . implode(', ', $set) . ' WHERE npub = ?')
       ->execute($params);

    if ($meta || $stats) {
        $updated++;
        echo "  OK: @" . ($meta['name'] ?? $npub)
            . ($stats ? " (followers:{$stats['followers']} posts:{$stats['posts']})" : '') . "\n";
    } else {
        echo "  SKIP (pas de données): {$npub}\n";
    }

    usleep(300_000); // 0.3s entre chaque requête
}

echo "[update_nostr_cache] Terminé : {$updated} mis à jour, {$errors} erreurs — " . date('Y-m-d H:i:s') . "\n";
// fetchPrimalData, npubToHex : fournis par _helpers.php (require_once ci-dessus)
