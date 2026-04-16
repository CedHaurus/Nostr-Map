<?php
/**
 * POST /api/auth.php
 * Authentification NIP-07 / NIP-98.
 *
 * Body : { "event": { ...signed kind:27235 event... } }
 * Retour : { "token": "...", "npub": "...", "slug": "...", "isNew": bool }
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Méthode non autorisée', 405);
}

// ─── Lire le body ────────────────────────────────────────────────────────────

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['event']) || !is_array($body['event'])) {
    jsonError('Body invalide : { event: {...} } attendu');
}

$event = $body['event'];

// ─── Vérifications NIP-98 ────────────────────────────────────────────────────

// Kind doit être 27235
if ((int)($event['kind'] ?? 0) !== 27235) {
    jsonError('Kind invalide : 27235 attendu');
}

// Vérifier les tags NIP-98
$hasUrlTag    = false;
$hasMethodTag = false;
$expectedUrl  = (getenv('APP_URL') ?: 'https://nostrmap.fr') . '/api/auth.php';

foreach ($event['tags'] ?? [] as $tag) {
    if ($tag[0] === 'u' && isset($tag[1])) {
        // Accepter avec ou sans trailing slash, http ou https
        $tagUrl = rtrim($tag[1], '/');
        $expUrl = rtrim($expectedUrl, '/');
        // Comparer sans protocole pour supporter http en dev
        $tagPath = preg_replace('#^https?://#', '', $tagUrl);
        $expPath = preg_replace('#^https?://#', '', $expUrl);
        if ($tagPath === $expPath) {
            $hasUrlTag = true;
        }
    }
    if ($tag[0] === 'method' && strtoupper($tag[1] ?? '') === 'POST') {
        $hasMethodTag = true;
    }
}

if (!$hasUrlTag)    jsonError('Tag ["u", url] manquant ou incorrect');
if (!$hasMethodTag) jsonError('Tag ["method", "POST"] manquant');

// ─── Vérifier la signature (age max 60s) ─────────────────────────────────────

if (!verifyNostrEvent($event, 60)) {
    jsonError('Signature Nostr invalide ou event expiré', 401);
}

// ─── Convertir pubkey hex → npub ─────────────────────────────────────────────

$pubkeyHex = $event['pubkey'];
$npub      = hexToNpub($pubkeyHex);

// ─── Créer ou mettre à jour le profil ────────────────────────────────────────

$db   = getDB();
$stmt = $db->prepare('SELECT npub, slug, status FROM profiles WHERE npub = ?');
$stmt->execute([$npub]);
$profile = $stmt->fetch();

$isNew = false;

if (!$profile) {
    // Nouveau profil
    $isNew = true;
    $slug  = generateSlug($npub);
    $stmt  = $db->prepare(
        'INSERT INTO profiles (npub, slug, last_login, registered_at)
         VALUES (?, ?, NOW(), NOW())'
    );
    $stmt->execute([$npub, $slug]);
    $profile = ['npub' => $npub, 'slug' => $slug, 'status' => 'active'];
    logUserActivity($npub, 'register', 'profile', $npub, "Slug: {$slug}");
    // Pré-charger les métadonnées Nostr immédiatement (nom, avatar, bio, stats)
    warmProfileCache($npub, $db);
} else {
    if ($profile['status'] === 'banned') {
        jsonError('Ce compte est banni', 403);
    }
    $slug = $profile['slug'];
    $db->prepare('UPDATE profiles SET last_login = NOW() WHERE npub = ?')
       ->execute([$npub]);
    logUserActivity($npub, 'login', 'profile', $npub);
}

// ─── Générer le JWT (24h) ────────────────────────────────────────────────────

$token = jwtEncode([
    'sub'  => $npub,
    'slug' => $slug,
    'iat'  => time(),
    'exp'  => time() + 86400,
]);

jsonOk([
    'token' => $token,
    'npub'  => $npub,
    'slug'  => $slug,
    'isNew' => $isNew,
]);
