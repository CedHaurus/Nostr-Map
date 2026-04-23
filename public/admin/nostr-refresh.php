<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
requireAdmin(); // modo+

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['error' => 'Method not allowed']));
}

$body  = json_decode(file_get_contents('php://input'), true) ?? [];
$token = $body['csrf_token'] ?? '';
if (!hash_equals(csrfToken(), $token)) {
    http_response_code(403);
    exit(json_encode(['error' => 'CSRF invalid']));
}

$npub = trim($body['npub'] ?? '');
if (!isValidNpub($npub)) {
    http_response_code(400);
    exit(json_encode(['error' => 'Invalid npub']));
}

$db     = getDB();
$exists = $db->prepare('SELECT 1 FROM profiles WHERE npub = ?');
$exists->execute([$npub]);
if (!$exists->fetchColumn()) {
    http_response_code(404);
    exit(json_encode(['error' => 'Profile not found']));
}

$allowed = ['cached_name', 'cached_avatar', 'cached_bio', 'cached_nip05',
            'nostr_created_at', 'nostr_followers', 'nostr_posts'];
$set = []; $params = [];
$statsFields = ['nostr_created_at', 'nostr_followers', 'nostr_posts'];
$hasStatsUpdate = false;

foreach ($allowed as $f) {
    if (!array_key_exists($f, $body) || $body[$f] === null || $body[$f] === '') continue;
    $val = $body[$f];
    if (in_array($f, $statsFields, true)) {
        $val = max(0, (int)$val);
        $hasStatsUpdate = true;
    } else {
        $val = mb_substr((string)$val, 0, match($f) {
            'cached_name'   => 100,
            'cached_avatar' => 500,
            'cached_nip05'  => 200,
            default         => 2000,
        });
    }
    $set[]    = "{$f} = ?";
    $params[] = $val;
}

if ($set) {
    if ($hasStatsUpdate) {
        $set[] = 'last_stats_fetch = NOW()';
    }
    $set[]    = 'last_fetch = NOW()';
    $params[] = $npub;
    $db->prepare('UPDATE profiles SET ' . implode(', ', $set) . ' WHERE npub = ?')
       ->execute($params);
    logActivity('nostr_refresh', 'profile', $npub, count($set) - 1 . ' champs mis à jour');
}

echo json_encode(['success' => true, 'updated' => count($set)]);
