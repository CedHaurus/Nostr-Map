<?php
declare(strict_types=1);
require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Method not allowed', 405);

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$npub   = trim($body['npub']   ?? '');
$reason = trim($body['reason'] ?? '');
$details = mb_substr(trim($body['details'] ?? ''), 0, 500);
$reporterNpub = trim($body['reporter_npub'] ?? '');

$allowed = ['usurpation','retrait','doublon'];

if (!$npub || !preg_match('/^npub1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]{58,}$/i', $npub)) jsonError('Profil invalide');
if (!in_array($reason, $allowed, true))  jsonError('Motif invalide');

$db = getDB();

// Obtenir l'IP du client (Caddy pose X-Real-IP ; pas de confiance à X-Forwarded-For injectable)
$ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';

// Vérifier IP bloquée
$blocked = $db->prepare(
    'SELECT 1 FROM blocked_ips WHERE ip = ? AND (expires_at IS NULL OR expires_at > NOW())'
);
$blocked->execute([$ip]);
if ($blocked->fetchColumn()) jsonError('Action non autorisée.', 403);

// Vérifier que le profil existe et n'est pas sanctuarisé
$profileRow = $db->prepare('SELECT status, protected FROM profiles WHERE npub = ?');
$profileRow->execute([$npub]);
$profileRow = $profileRow->fetch();
if (!$profileRow || $profileRow['status'] !== 'active') jsonError('Profil introuvable', 404);
if ($profileRow['protected']) jsonError('Ce profil ne peut pas être signalé.', 403);

$rl = $db->prepare(
    'SELECT 1 FROM reports WHERE npub = ? AND reporter_ip = ? AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)'
);
$rl->execute([$npub, $ip]);
if ($rl->fetchColumn()) jsonError('Vous avez déjà signalé ce profil récemment.', 429);

// Récupérer le slug pour le log
$slugRow = $db->prepare('SELECT slug FROM profiles WHERE npub = ?');
$slugRow->execute([$npub]);
$slug = $slugRow->fetchColumn() ?: null;

$db->prepare(
    'INSERT INTO reports (npub, slug, reason, details, reporter_ip, reporter_npub)
     VALUES (?, ?, ?, ?, ?, ?)'
)->execute([
    $npub,
    $slug,
    $reason,
    $details ?: null,
    $ip,
    $reporterNpub ?: null,
]);

jsonOk(['success' => true]);
