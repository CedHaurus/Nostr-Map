<?php
/**
 * sitemap.php — Sitemap XML dynamique
 * Route nginx : /sitemap.xml → sitemap.php
 */

declare(strict_types=1);
require_once '/var/www/config/db.php';

$appUrl = rtrim(getenv('APP_URL') ?: 'https://nostrmap.fr', '/');

header('Content-Type: application/xml; charset=utf-8');
header('X-Robots-Tag: noindex');

$today = date('Y-m-d');

// Pages statiques
$static = [
    ['loc' => '/',                  'changefreq' => 'daily',   'priority' => '1.0', 'lastmod' => $today],
    ['loc' => '/faq',               'changefreq' => 'monthly', 'priority' => '0.7', 'lastmod' => $today],
    ['loc' => '/soumettre',         'changefreq' => 'monthly', 'priority' => '0.6', 'lastmod' => $today],
    ['loc' => '/connexion',         'changefreq' => 'monthly', 'priority' => '0.5', 'lastmod' => $today],
    ['loc' => '/extension',         'changefreq' => 'monthly', 'priority' => '0.6', 'lastmod' => $today],
    ['loc' => '/relay',             'changefreq' => 'monthly', 'priority' => '0.6', 'lastmod' => $today],
    ['loc' => '/contact',           'changefreq' => 'yearly',  'priority' => '0.4', 'lastmod' => $today],
    ['loc' => '/mentions-legales',  'changefreq' => 'yearly',  'priority' => '0.3', 'lastmod' => $today],
    ['loc' => '/privacy-nostr-map-extension', 'changefreq' => 'yearly', 'priority' => '0.3', 'lastmod' => $today],
];

// Profils actifs
$profiles = [];
try {
    $db   = getDB();
    $stmt = $db->query(
        'SELECT slug, last_fetch FROM profiles WHERE status = "active" ORDER BY last_fetch DESC'
    );
    $profiles = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Silently degrade: sitemap without profiles
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($static as $page) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($appUrl . $page['loc'], ENT_XML1) . "</loc>\n";
    echo "    <lastmod>{$page['lastmod']}</lastmod>\n";
    echo "    <changefreq>{$page['changefreq']}</changefreq>\n";
    echo "    <priority>{$page['priority']}</priority>\n";
    echo "  </url>\n";
}

foreach ($profiles as $p) {
    $lastmod = $p['last_fetch'] ? substr($p['last_fetch'], 0, 10) : $today;
    $loc     = $appUrl . '/p/' . rawurlencode($p['slug']);
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($loc, ENT_XML1) . "</loc>\n";
    echo "    <lastmod>{$lastmod}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    echo "  </url>\n";
}

echo '</urlset>' . "\n";
