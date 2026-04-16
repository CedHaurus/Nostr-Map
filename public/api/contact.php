<?php
/**
 * /api/contact.php
 *
 * POST (auth JWT) — Envoyer un message à l'équipe
 * Body JSON : { motif: string, message: string }
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Méthode non autorisée', 405);

$auth = requireAuth();
$npub = $auth['sub'];

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$motif   = trim($body['motif']   ?? '');
$message = trim($body['message'] ?? '');

$allowedMotifs = ['correction', 'retrait', 'suggestion', 'bug', 'autre'];
if (!in_array($motif, $allowedMotifs, true)) {
    jsonError('Motif invalide');
}
if (strlen($message) < 10) {
    jsonError('Message trop court (10 caractères minimum)');
}
if (strlen($message) > 2000) {
    jsonError('Message trop long (2000 caractères maximum)');
}

$db = getDB();

// Récupérer le nom du profil
$stmt = $db->prepare('SELECT cached_name FROM profiles WHERE npub = ? AND status != "banned"');
$stmt->execute([$npub]);
$profile = $stmt->fetch();
if (!$profile) {
    jsonError('Profil introuvable ou banni', 403);
}
$name = $profile['cached_name'] ?? null;

// Anti-spam : max 3 messages par npub par 24h
$countStmt = $db->prepare(
    'SELECT COUNT(*) FROM contact_messages WHERE npub = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
);
$countStmt->execute([$npub]);
if ((int)$countStmt->fetchColumn() >= 3) {
    jsonError('Limite atteinte : 3 messages par 24h.', 429);
}

$db->prepare(
    'INSERT INTO contact_messages (npub, cached_name, motif, message) VALUES (?, ?, ?, ?)'
)->execute([$npub, $name, $motif, $message]);

logUserActivity($npub, 'contact', 'message', null, "Motif: {$motif}");

jsonOk(['ok' => true, 'message' => "Message envoyé. L'équipe vous répondra via Nostr."]);
