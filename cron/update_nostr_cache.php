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

    // Métadonnées + stats depuis Primal (un seul appel)
    [$meta, $stats] = fetchPrimalData($hex);

    // Mettre à jour last_fetch dans tous les cas (même si pas de données)
    $set    = ['last_fetch = NOW()'];
    $params = [];

    if ($meta) {
        if (!empty($meta['name']))       { array_unshift($set, 'cached_name = ?');      array_unshift($params, mb_substr($meta['name'],    0, 100)); }
        if (!empty($meta['picture']))    { array_unshift($set, 'cached_avatar = ?');    array_unshift($params, mb_substr($meta['picture'], 0, 500)); }
        if (!empty($meta['about']))      { array_unshift($set, 'cached_bio = ?');       array_unshift($params, mb_substr($meta['about'],   0, 2000)); }
        if (!empty($meta['nip05']))      { array_unshift($set, 'cached_nip05 = ?');     array_unshift($params, mb_substr($meta['nip05'],   0, 200)); }
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

// ── Helpers ──────────────────────────────────────────────────────────────────

/**
 * Récupère profil (kind:0) + stats (kind:10000105) depuis cache2.primal.net en un seul appel.
 * Retourne [meta|null, stats|null]
 */
function fetchPrimalData(string $hex): array {
    $payload = json_encode(['user_profile', ['pubkey' => $hex]]);

    $ch = curl_init('https://cache2.primal.net/api');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERAGENT      => 'NostrMap-Cron/1.0 (+https://nostrmap.fr)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body || $code !== 200) return [null, null];

    $events = json_decode($body, true);
    if (!is_array($events)) return [null, null];

    $meta  = null;
    $stats = null;
    $best  = null;

    foreach ($events as $event) {
        if (!is_array($event)) continue;

        // kind:0 — métadonnées du profil
        if (($event['kind'] ?? -1) === 0) {
            if (!$best || ($event['created_at'] ?? 0) > ($best['created_at'] ?? 0)) {
                $best = $event;
            }
        }

        // kind:10000105 — stats Primal (followers, posts, etc.)
        if (($event['kind'] ?? -1) === 10000105) {
            $s = is_string($event['content'] ?? null)
                ? json_decode($event['content'], true)
                : null;
            if (is_array($s)) {
                $stats = [
                    'followers' => max(0, (int)($s['followers_count'] ?? 0)),
                    'posts'     => max(0, (int)($s['note_count']      ?? 0)),
                    'joined_at' => max(0, (int)($s['time_joined']     ?? 0)),
                ];
            }
        }
    }

    if ($best) {
        $content = is_string($best['content'] ?? null)
            ? json_decode($best['content'], true)
            : null;
        if (is_array($content)) {
            $meta = [
                'name'       => $content['name'] ?? $content['display_name'] ?? null,
                'picture'    => $content['picture'] ?? null,
                'about'      => $content['about'] ?? null,
                'nip05'      => $content['nip05'] ?? null,
            ];
        }
    }

    return [$meta, $stats];
}

/**
 * Convertit un npub bech32 en hex
 */
function npubToHex(string $npub): string {
    $CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    $str     = strtolower($npub);
    $sep     = strrpos($str, '1');
    if ($sep < 1) throw new Exception('Invalid npub');

    $dataStr = substr($str, $sep + 1, -6);
    $data5   = [];
    foreach (str_split($dataStr) as $c) {
        $idx = strpos($CHARSET, $c);
        if ($idx === false) throw new Exception("Invalid char: $c");
        $data5[] = $idx;
    }

    $acc = 0; $bits = 0; $bytes = [];
    foreach ($data5 as $v) {
        $acc   = ($acc << 5) | $v;
        $bits += 5;
        while ($bits >= 8) {
            $bits -= 8;
            $bytes[] = ($acc >> $bits) & 0xff;
        }
    }

    return implode('', array_map(fn($b) => sprintf('%02x', $b), $bytes));
}
