<?php
/**
 * GET /api/search.php?q=[terme]
 * Recherche dans : cached_name, slug, cached_nip05, display_handle (social_links)
 * Retourne max 20 résultats.
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonError('Méthode non autorisée', 405);
}

$q      = trim($_GET['q'] ?? '');
$limit  = min(40, max(1, (int)($_GET['limit'] ?? 20)));
$sort   = $_GET['sort']   ?? 'recent';   // 'recent' | 'actifs' | 'populaires' | 'random'
$offset = max(0, (int)($_GET['offset'] ?? 0));
$seed   = preg_match('/^\d+$/', $_GET['seed'] ?? '') ? (int)$_GET['seed'] : null;

const PAGE = 12;

// Mode grille sans recherche textuelle (accueil)
if ($q === '' && in_array($sort, ['recent', 'actifs', 'populaires', 'random'], true)) {
    $db = getDB();
    $orderBy = match($sort) {
        'actifs'     => 'p.nostr_posts DESC',
        'populaires' => 'p.nostr_followers DESC',
        'random'     => $seed !== null ? "RAND({$seed})" : 'RAND()',
        default      => 'p.registered_at DESC',
    };
    $stmt = $db->prepare(
        "SELECT p.npub, p.slug, p.cached_name, p.cached_avatar, p.cached_bio, p.cached_nip05,
                p.nostr_followers, p.nostr_posts,
                (p.last_login IS NULL) AS community_added
         FROM profiles p
         WHERE p.status = 'active'
         ORDER BY {$orderBy}
         LIMIT ? OFFSET ?"
    );
    $stmt->execute([PAGE + 1, $offset]);
    $rows    = $stmt->fetchAll();
    $hasMore = count($rows) > PAGE;
    $results = array_slice($rows, 0, PAGE);

    // Badges vérifiés
    $npubs = array_column($results, 'npub');
    $badges = [];
    if (!empty($npubs)) {
        $ph    = implode(',', array_fill(0, count($npubs), '?'));
        $stmt2 = $db->prepare("SELECT npub, platform FROM social_links WHERE npub IN ({$ph}) AND verified = 1");
        $stmt2->execute($npubs);
        foreach ($stmt2->fetchAll() as $row) {
            $badges[$row['npub']][] = $row['platform'];
        }
    }
    foreach ($results as &$p) {
        $p['verified_platforms'] = $badges[$p['npub']] ?? [];
        if ($p['cached_bio'] && mb_strlen($p['cached_bio']) > 100) {
            $p['cached_bio'] = mb_substr($p['cached_bio'], 0, 97) . '…';
        }
    }
    unset($p);
    jsonOk(['results' => $results, 'has_more' => $hasMore]);
}

if (strlen($q) < 2) {
    jsonOk(['results' => []]);
}

// Sanitiser : garder uniquement chars sûrs pour LIKE
$q = mb_substr($q, 0, 100);

$db    = getDB();
$like  = '%' . $q . '%';

$sql = "
    SELECT
        p.npub,
        p.slug,
        p.cached_name,
        p.cached_avatar,
        p.cached_bio,
        p.cached_nip05,
        p.nostr_followers,
        p.nostr_posts,
        p.registered_at,
        (p.last_login IS NULL) AS community_added
    FROM profiles p
    WHERE
        p.status = 'active'
        AND (
            p.cached_name   LIKE ? COLLATE utf8mb4_unicode_ci
            OR p.nostr_name LIKE ? COLLATE utf8mb4_unicode_ci
            OR p.slug       LIKE ? COLLATE utf8mb4_unicode_ci
            OR p.npub       LIKE ? COLLATE utf8mb4_unicode_ci
            OR p.cached_nip05 LIKE ? COLLATE utf8mb4_unicode_ci
            OR p.npub IN (
                SELECT npub FROM social_links
                WHERE display_handle LIKE ? COLLATE utf8mb4_unicode_ci
                   OR url            LIKE ? COLLATE utf8mb4_unicode_ci
            )
        )
    ORDER BY p.registered_at DESC
    LIMIT ?
";

$stmt = $db->prepare($sql);
$stmt->execute([$like, $like, $like, $like, $like, $like, $like, $limit]);
$results = $stmt->fetchAll();

// Pour chaque profil, récupérer les badges vérifiés
$npubs = array_column($results, 'npub');
$badges = [];

if (!empty($npubs)) {
    $placeholders = implode(',', array_fill(0, count($npubs), '?'));
    $stmt2 = $db->prepare(
        "SELECT npub, platform, verified
         FROM social_links
         WHERE npub IN ({$placeholders}) AND verified = 1"
    );
    $stmt2->execute($npubs);
    foreach ($stmt2->fetchAll() as $row) {
        $badges[$row['npub']][] = $row['platform'];
    }
}

foreach ($results as &$p) {
    $p['verified_platforms'] = $badges[$p['npub']] ?? [];
    // Tronquer la bio
    if ($p['cached_bio'] && mb_strlen($p['cached_bio']) > 100) {
        $p['cached_bio'] = mb_substr($p['cached_bio'], 0, 97) . '…';
    }
}
unset($p);

jsonOk(['results' => $results]);
