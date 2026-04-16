<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

// Auth obligatoire : seul le propriétaire du profil peut mettre à jour son cache
$auth = requireAuth();
$authNpub = $auth['sub'] ?? '';

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$npub = trim($body['npub'] ?? '');

if (!$npub || strlen($npub) < 60 || !str_starts_with($npub, 'npub1')
    || !preg_match('/^npub1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]+$/i', $npub)) {
    jsonError('Invalid npub');
}

// L'utilisateur ne peut mettre à jour que son propre profil
if ($npub !== $authNpub) {
    jsonError('Non autorisé : vous ne pouvez modifier que votre propre profil.', 403);
}

$db   = getDB();
$stmt = $db->prepare('SELECT last_fetch, last_stats_fetch FROM profiles WHERE npub = ? AND status = "active"');
$stmt->execute([$npub]);
$row  = $stmt->fetch();
if (!$row) jsonError('Not found', 404);

// Rate-limit granulaire :
//   - stats (followers/posts/created_at) → 10 min via last_stats_fetch (indépendant du cron)
//   - metadata (name/avatar/bio/nip05)   → 1h via last_fetch
$statsFields = ['nostr_followers', 'nostr_posts', 'nostr_created_at'];
$metaFields  = ['cached_name', 'cached_avatar', 'cached_bio', 'cached_nip05'];

$hasStats    = !empty(array_intersect(array_keys($body), $statsFields));
$hasMeta     = !empty(array_intersect(array_keys($body), $metaFields));

$statsAllowed = !$hasStats
    || !$row['last_stats_fetch']
    || (time() - strtotime($row['last_stats_fetch'])) >= 600;

$metaAllowed  = !$hasMeta
    || !$row['last_fetch']
    || (time() - strtotime($row['last_fetch'])) >= 3600;

if (!$statsAllowed && !$metaAllowed) {
    jsonOk(['skipped' => true]);
}

$set = []; $params = [];

// Champs stats (si autorisés)
if ($statsAllowed) {
    foreach ($statsFields as $f) {
        if (!array_key_exists($f, $body) || $body[$f] === null || $body[$f] === '') continue;
        $set[]    = "{$f} = ?";
        $params[] = max(0, (int)$body[$f]);
    }
    if (!empty(array_intersect(array_keys($body), $statsFields))) {
        $set[] = 'last_stats_fetch = NOW()';
    }
}

// Champs metadata (si autorisés)
if ($metaAllowed) {
    foreach ($metaFields as $f) {
        if (!array_key_exists($f, $body) || $body[$f] === null || $body[$f] === '') continue;
        $val      = mb_substr((string)$body[$f], 0, match($f) {
            'cached_name'   => 100,
            'cached_avatar' => 500,
            'cached_nip05'  => 200,
            default         => 2000,
        });
        $set[]    = "{$f} = ?";
        $params[] = $val;
    }
    if ($hasMeta) {
        $set[] = 'last_fetch = NOW()';
    }
}

if ($set) {
    $params[] = $npub;
    $db->prepare('UPDATE profiles SET ' . implode(', ', $set) . ' WHERE npub = ?')
       ->execute($params);
}

jsonOk(['success' => true]);
