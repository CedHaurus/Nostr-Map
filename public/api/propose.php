<?php
/**
 * POST /api/propose.php
 * Soumettre un profil Nostr pour ajout à l'annuaire.
 * Requiert authentification JWT (NIP-07).
 */

declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Méthode non autorisée', 405);

$auth = requireAuth();
$proposedBy = $auth['sub']; // npub de l'utilisateur connecté

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$npub = trim($body['npub'] ?? '');

// Validation npub
if (!$npub) jsonError('Le npub est requis');
if (strlen($npub) < 60 || !str_starts_with($npub, 'npub1')
    || !preg_match('/^npub1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]+$/i', $npub)) {
    jsonError('Format npub invalide');
}

// Un utilisateur ne peut pas se proposer lui-même
if ($npub === $proposedBy) {
    jsonError('Vous ne pouvez pas vous proposer vous-même. Utilisez la page Mon Profil.');
}

$db = getDB();

// Vérifier que le npub n'est pas déjà dans l'annuaire
$existing = $db->prepare('SELECT slug FROM profiles WHERE npub = ? AND status != "banned"');
$existing->execute([$npub]);
if ($existing->fetch()) {
    jsonError('Ce profil est déjà dans l\'annuaire.', 409);
}

// Vérifier qu'il n'y a pas déjà une proposition en attente pour ce npub
$pending = $db->prepare('SELECT id FROM proposals WHERE npub_proposed = ? AND status = "pending"');
$pending->execute([$npub]);
if ($pending->fetch()) {
    jsonError('Une proposition est déjà en attente pour ce profil.', 409);
}

// Valider les liens sociaux
$linksRaw = $body['links'] ?? [];
$allowedPlatforms = ['x', 'mastodon', 'bluesky', 'youtube'];
$links = [];

foreach ((array)$linksRaw as $link) {
    $platform = strtolower(trim($link['platform'] ?? ''));
    $handle   = trim($link['handle'] ?? '');
    $url      = trim($link['url'] ?? '');

    if (!in_array($platform, $allowedPlatforms)) continue;
    if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) continue;
    if (strlen($url) > 500) continue;

    $links[] = [
        'platform' => $platform,
        'handle'   => mb_substr($handle, 0, 100),
        'url'      => $url,
    ];

    if (count($links) >= 10) break;
}

$message     = mb_substr(trim($body['message'] ?? ''), 0, 1000) ?: null;
$cachedName  = mb_substr(trim($body['cached_name'] ?? ''), 0, 100) ?: null;
$cachedAvatar= mb_substr(trim($body['cached_avatar'] ?? ''), 0, 500) ?: null;
$linksJson   = !empty($links) ? json_encode($links, JSON_UNESCAPED_UNICODE) : null;

$db->prepare(
    'INSERT INTO proposals (npub_proposed, proposed_by, message, links_json, cached_name, cached_avatar)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([$npub, $proposedBy, $message, $linksJson, $cachedName, $cachedAvatar]);

logUserActivity($proposedBy, 'propose_profile', 'proposal', $npub,
    'Proposé: ' . ($cachedName ?: $npub) . ', liens: ' . count($links));

jsonOk(['success' => true, 'message' => 'Proposition envoyée. Elle sera examinée par un modérateur.']);
