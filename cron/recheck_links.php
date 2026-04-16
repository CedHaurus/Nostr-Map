#!/usr/bin/env php
<?php
/**
 * cron/recheck_links.php
 * Revérifie périodiquement les liens déjà vérifiés (toutes les 24h).
 * Marque comme non-vérifié si le challenge a disparu.
 *
 * Lancer via cron :
 *   0 4 * * * docker exec nostrmap_php php /var/www/cron/recheck_links.php
 */

declare(strict_types=1);

require_once '/var/www/config/db.php';

$batchSize = (int)(getenv('CRON_RECHECK_BATCH') ?: 50);
$db        = getDB();

// Récupérer les liens vérifiés qui n'ont pas été revérifiés depuis 24h
$links = $db->prepare(
    "SELECT sl.id, sl.npub, sl.url, sl.challenge
     FROM social_links sl
     JOIN profiles p ON p.npub = sl.npub
     WHERE sl.verified = 1
       AND p.status = 'active'
       AND (sl.last_check IS NULL OR sl.last_check < DATE_SUB(NOW(), INTERVAL 24 HOUR))
     ORDER BY sl.last_check ASC
     LIMIT ?"
);
$links->execute([$batchSize]);
$rows = $links->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    echo "[recheck_links] Aucun lien à revérifier.\n";
    exit(0);
}

$checked   = 0;
$revoked   = 0;
$confirmed = 0;

$stmt_update_last  = $db->prepare('UPDATE social_links SET last_check = NOW() WHERE id = ?');
$stmt_revoke       = $db->prepare('UPDATE social_links SET verified = 0, verified_at = NULL, last_check = NOW() WHERE id = ?');

foreach ($rows as $link) {
    $url       = $link['url'];
    $challenge = $link['challenge'];
    $id        = (int)$link['id'];

    // Valider l'URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        $stmt_revoke->execute([$id]);
        $revoked++;
        $checked++;
        continue;
    }

    // Bloquer les IPs internes (SSRF)
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host || preg_match('/^(localhost|127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/i', $host)) {
        $stmt_revoke->execute([$id]);
        $revoked++;
        $checked++;
        continue;
    }

    // Fetch de l'URL
    $content   = '';
    $totalSize = 0;
    $maxSize   = 512 * 1024;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_USERAGENT      => 'NostrMap-Verifier/1.0 (+https://nostrmap.fr)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$content, &$totalSize, $maxSize) {
        $totalSize += strlen($data);
        if ($totalSize > $maxSize) return -1;
        $content .= $data;
        return strlen($data);
    });

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || $httpCode < 200 || $httpCode >= 400) {
        // Erreur réseau : garder comme vérifié (problème temporaire)
        $stmt_update_last->execute([$id]);
        $checked++;
        continue;
    }

    if (strpos($content, $challenge) !== false) {
        // Challenge toujours présent
        $stmt_update_last->execute([$id]);
        $confirmed++;
    } else {
        // Challenge disparu → révoquer
        $stmt_revoke->execute([$id]);
        $revoked++;
    }

    $checked++;
    // Petite pause pour ne pas saturer les serveurs distants
    usleep(200_000); // 0.2s
}

echo "[recheck_links] {$checked} vérifiés, {$confirmed} confirmés, {$revoked} révoqués — " . date('Y-m-d H:i:s') . "\n";
