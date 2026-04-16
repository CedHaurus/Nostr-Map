<?php
/**
 * POST /api/verify.php  (auth requise)
 * Body : { "link_id": int }
 *
 * Fetch l'URL publique, cherche le code challenge dans le HTML.
 * Met à jour verified=true si trouvé.
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('Méthode non autorisée', 405);
}

$auth   = requireAuth();
$npub   = $auth['sub'];
$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$linkId = (int)($body['link_id'] ?? 0);

if ($linkId <= 0) jsonError('link_id invalide');

$db = getDB();

// Vérifier que le lien appartient à l'utilisateur
$stmt = $db->prepare(
    'SELECT id, url, challenge, verified FROM social_links WHERE id = ? AND npub = ?'
);
$stmt->execute([$linkId, $npub]);
$link = $stmt->fetch();

if (!$link) jsonError('Lien introuvable', 404);

if ($link['verified']) {
    jsonOk(['verified' => true, 'message' => 'Lien déjà vérifié.']);
}

// ─── Fetch de l'URL ──────────────────────────────────────────────────────────

$url = $link['url'];
$challenge = $link['challenge'];

if (!filter_var($url, FILTER_VALIDATE_URL)) {
    jsonError('URL invalide en base');
}

// Forcer HTTPS
$scheme = parse_url($url, PHP_URL_SCHEME);
if ($scheme !== 'https') {
    jsonError('Seules les URLs HTTPS sont autorisées');
}

// Bloquer les URLs internes (SSRF) — validation hostname + résolution DNS
$host = parse_url($url, PHP_URL_HOST);
if (!$host) jsonError('URL sans hôte');

// Bloquer les hostnames évidents
$localHostPatterns = [
    '/^localhost$/i',
    '/^127\./',
    '/^10\./',
    '/^192\.168\./',
    '/^172\.(1[6-9]|2\d|3[01])\./',
    '/^::1$/',
    '/^0\./',
    '/^169\.254\./',
    '/^fc00:/i',
    '/^fd/i',
    '/^fe80:/i',
    '/\.local$/i',
    '/\.internal$/i',
    '/\.docker$/i',
];
foreach ($localHostPatterns as $pattern) {
    if (preg_match($pattern, $host)) {
        jsonError('URL non autorisée');
    }
}

// Résolution DNS et blocage des IP privées/réservées (IPv4 + IPv6)
$ips4 = gethostbynamel($host) ?: [];
$ips6 = [];
$aaaaRecords = dns_get_record($host, DNS_AAAA);
if (is_array($aaaaRecords)) {
    foreach ($aaaaRecords as $r) {
        if (!empty($r['ipv6'])) $ips6[] = $r['ipv6'];
    }
}
$allIps = array_merge($ips4, $ips6);
if (!$allIps) jsonError('Impossible de résoudre le nom d\'hôte');

foreach ($allIps as $resolvedIp) {
    if (!filter_var($resolvedIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        jsonError('URL non autorisée : l\'adresse IP résolue est privée ou réservée');
    }
}

// ─── Cas spécial X / Twitter : vérification via oEmbed ───────────────────────
// X rend ses pages en JS côté client, un curl classique ne voit pas la bio.
// Solution : l'utilisateur poste un tweet avec le code, on vérifie via oEmbed.

$isXHost = preg_match('/^(www\.)?(x\.com|twitter\.com)$/i', $host);

if ($isXHost) {
    // Vérifier que c'est bien une URL de tweet (pas un profil)
    $isStatusUrl = preg_match('#/(status|statuses)/\d+#i', parse_url($url, PHP_URL_PATH));

    if (!$isStatusUrl) {
        $db->prepare('UPDATE social_links SET last_check = NOW() WHERE id = ?')->execute([$linkId]);
        jsonOk([
            'verified' => false,
            'message'  => 'X ne permet pas la vérification via l\'URL de profil. Postez un tweet contenant le code challenge et collez l\'URL de ce tweet.',
        ]);
    }

    // Fetch via oEmbed (API publique, pas d'auth requise)
    $oembedUrl = 'https://publish.twitter.com/oembed?url=' . urlencode($url) . '&omit_script=true';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $oembedUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'NostrMap-Verifier/1.0 (+https://nostrmap.fr)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    ]);
    $oembedBody = curl_exec($ch);
    $oembedCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $oembedErr  = curl_error($ch);
    curl_close($ch);

    $db->prepare('UPDATE social_links SET last_check = NOW() WHERE id = ?')->execute([$linkId]);

    if ($oembedErr || $oembedCode < 200 || $oembedCode >= 400) {
        jsonOk(['verified' => false, 'message' => 'Impossible de récupérer le tweet via oEmbed. Vérifiez que le tweet est public.']);
    }

    $oembedData = json_decode($oembedBody, true);
    $tweetHtml  = $oembedData['html'] ?? '';

    if (strpos($tweetHtml, $challenge) !== false) {
        $db->prepare('UPDATE social_links SET verified = 1, verified_at = NOW() WHERE id = ?')->execute([$linkId]);
        logUserActivity($npub, 'verify_link', 'link', (string)$linkId, 'URL: ' . substr($url, 0, 100));
        jsonOk(['verified' => true, 'message' => 'Lien X vérifié avec succès !']);
    }

    jsonOk([
        'verified' => false,
        'message'  => "Code challenge introuvable dans le tweet. Assurez-vous que \"{$challenge}\" est bien dans le texte du tweet.",
    ]);
}

// ─── Cas spécial Bluesky : AT Protocol API ────────────────────────────────────
// bsky.app est une SPA React, curl ne voit pas la bio.
// L'AT Protocol expose une API publique JSON → on parse le handle depuis l'URL.

$isBskyHost = preg_match('/^(www\.)?bsky\.app$/i', $host);

if ($isBskyHost) {
    // Extraire le handle/DID depuis l'URL : /profile/{actor}
    preg_match('#/profile/([^/?#]+)#i', parse_url($url, PHP_URL_PATH), $m);
    $actor = $m[1] ?? '';

    if (!$actor) {
        $db->prepare('UPDATE social_links SET last_check = NOW() WHERE id = ?')->execute([$linkId]);
        jsonOk(['verified' => false, 'message' => 'URL Bluesky invalide — format attendu : https://bsky.app/profile/handle']);
    }

    $apiUrl = 'https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile?actor=' . urlencode($actor);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'NostrMap-Verifier/1.0 (+https://nostrmap.fr)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $bskyBody = curl_exec($ch);
    $bskyCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $bskyErr  = curl_error($ch);
    curl_close($ch);

    $db->prepare('UPDATE social_links SET last_check = NOW() WHERE id = ?')->execute([$linkId]);

    if ($bskyErr || $bskyCode < 200 || $bskyCode >= 400) {
        jsonOk(['verified' => false, 'message' => 'Impossible de récupérer le profil Bluesky. Vérifiez que le profil est public.']);
    }

    $bskyData    = json_decode($bskyBody, true);
    $description = $bskyData['description'] ?? '';

    if (strpos($description, $challenge) !== false) {
        $db->prepare('UPDATE social_links SET verified = 1, verified_at = NOW() WHERE id = ?')->execute([$linkId]);
        logUserActivity($npub, 'verify_link', 'link', (string)$linkId, 'URL: ' . substr($url, 0, 100));
        jsonOk(['verified' => true, 'message' => 'Lien Bluesky vérifié avec succès !']);
    }

    jsonOk([
        'verified' => false,
        'message'  => "Code challenge introuvable dans la bio Bluesky. Assurez-vous que \"{$challenge}\" est bien dans votre bio.",
    ]);
}

// ─── Fetch standard (Mastodon, YouTube) ──────────────────────────────────────
// YouTube embarque la description dans ytInitialData (~600 Ko dans la page),
// donc la limite est portée à 1.5 Mo pour les URLs YouTube.

$isYouTubeHost = preg_match('/^(www\.)?(youtube\.com|youtu\.be)$/i', $host);
$maxSize       = $isYouTubeHost ? 1536 * 1024 : 512 * 1024; // 1.5 Mo / 512 Ko

// Anti TOCTOU/DNS rebinding : forcer cURL à utiliser l'IP déjà validée
// plutôt que de relancer une résolution DNS (qui pourrait pointer ailleurs entre-temps)
$pinnedIp = $allIps[0];

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_USERAGENT      => 'NostrMap-Verifier/1.0 (+https://nostrmap.fr)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
    CURLOPT_HTTPHEADER     => ['Accept: text/html,application/xhtml+xml'],
    CURLOPT_RESOLVE        => ["{$host}:443:{$pinnedIp}"],
]);

// Callback pour capturer sans CURLOPT_RETURNTRANSFER (conflit PHP/curl avec WRITEFUNCTION)
$totalSize = 0;
$content   = '';
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use (&$content, &$totalSize, $maxSize) {
    $totalSize += strlen($data);
    if ($totalSize > $maxSize) return -1; // coupe le transfert
    $content .= $data;
    return strlen($data);
});

curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrNo = curl_errno($ch);
$curlErr   = curl_error($ch);
curl_close($ch);

// ─── Mise à jour en base (last_check même en cas d'échec) ────────────────────
$db->prepare('UPDATE social_links SET last_check = NOW() WHERE id = ?')
   ->execute([$linkId]);

// CURLE_WRITE_ERROR (23) = le callback a retourné -1 pour couper après 512 Ko
// On a déjà le contenu partiel, on continue la vérification normalement
if ($curlErr && $curlErrNo !== 23) {
    jsonOk(['verified' => false, 'message' => "Erreur réseau : {$curlErr}"]);
}

if ($httpCode < 200 || $httpCode >= 400) {
    jsonOk(['verified' => false, 'message' => "HTTP {$httpCode} : page inaccessible."]);
}

// ─── Chercher le challenge ───────────────────────────────────────────────────

if (strpos($content, $challenge) !== false) {
    $db->prepare(
        'UPDATE social_links SET verified = 1, verified_at = NOW() WHERE id = ?'
    )->execute([$linkId]);

    logUserActivity($npub, 'verify_link', 'link', (string)$linkId, "URL: " . substr($url, 0, 100));
    jsonOk(['verified' => true, 'message' => 'Lien vérifié avec succès !']);
}

jsonOk([
    'verified' => false,
    'message'  => "Code challenge introuvable dans la page. Assurez-vous que \"{$challenge}\" est bien visible dans le HTML de la page.",
]);
