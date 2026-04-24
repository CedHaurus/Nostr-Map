<?php
/**
 * p.php — Rendu serveur des meta OG pour les pages profil.
 * Les bots (Twitter, iMessage, WhatsApp…) voient les vraies données.
 * Le navigateur reçoit le même HTML que p.html, enrichi des meta tags.
 */

declare(strict_types=1);
require_once '/var/www/config/db.php';

$slug = trim($_GET['slug'] ?? '');

// Sécuriser le slug
if (!$slug || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
    readfile(__DIR__ . '/p.html');
    exit;
}

$db   = getDB();
$stmt = $db->prepare(
    'SELECT npub, slug, cached_name, cached_avatar, cached_bio, cached_nip05, status,
            nostr_created_at, nostr_followers, nostr_posts,
            (last_login IS NULL) AS community_added
     FROM profiles WHERE slug = ? AND status = "active"'
);
$stmt->execute([$slug]);
$p = $stmt->fetch(PDO::FETCH_ASSOC);

// Profil introuvable → servir p.html sans modifier (JS gérera le 404)
if (!$p) {
    readfile(__DIR__ . '/p.html');
    exit;
}

$appUrl  = rtrim(getenv('APP_URL') ?: 'https://nostrmap.fr', '/');

function metaText(?string $value, string $fallback, int $maxLen): string {
    $text = trim((string)($value ?? ''));
    $text = strip_tags($text);
    $text = preg_replace('/\s+/u', ' ', $text) ?: '';
    if ($text === '') $text = $fallback;
    return mb_substr($text, 0, $maxLen);
}

$displayName = metaText($p['cached_name'] ?: '@' . $p['slug'], '@' . $p['slug'], 100);
$bioText     = metaText($p['cached_bio'] ?? '', 'Profil Nostr francophone sur Nostr Map.', 200);
$name        = htmlspecialchars($displayName, ENT_QUOTES);
$bio         = htmlspecialchars($bioText, ENT_QUOTES);
$avatar  = $p['cached_avatar'] ? htmlspecialchars($p['cached_avatar'], ENT_QUOTES) : '';
$url     = $appUrl . '/p/' . rawurlencode($p['slug']);
$title   = $name . ' — Nostr Map';
$nip05   = $p['cached_nip05'] ? htmlspecialchars($p['cached_nip05'], ENT_QUOTES) : '';

// Lire p.html et injecter les meta OG juste avant </head>
$html = file_get_contents(__DIR__ . '/p.html');

// Image OG : bannière générée dynamiquement (1200×630) avec nom + avatar + bio
$ogImageParams = http_build_query([
    'title'  => $displayName,
    'sub'    => mb_substr($bioText, 0, 80),
    'avatar' => $p['cached_avatar'] ?: '',
    'slug'   => $p['slug'],
]);
$ogImage = $appUrl . '/og-image.php?' . $ogImageParams;

$imageAlt  = htmlspecialchars('Profil Nostr de ' . $displayName . ' sur Nostr Map', ENT_QUOTES);
$ldJson = json_encode([
    '@context'   => 'https://schema.org',
    '@type'      => 'ProfilePage',
    'url'        => $url,
    'name'       => $displayName,
    'description'=> $bioText,
    'mainEntity' => [
        '@type'      => 'Person',
        'name'       => $displayName,
        'description'=> $bioText,
        'url'        => $url,
        'identifier' => $p['npub'],
    ],
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

$ogTags = '
  <!-- Open Graph / Twitter Cards (injectés par p.php) -->
  <meta property="og:type"        content="profile" />
  <meta property="og:url"         content="' . $url . '" />
  <meta property="og:title"       content="' . $title . '" />
  <meta property="og:description" content="' . $bio . '" />
  <meta property="og:image"       content="' . htmlspecialchars($ogImage, ENT_QUOTES) . '" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height"content="630" />
  <meta property="og:image:alt"   content="' . $imageAlt . '" />
  <meta property="og:site_name"   content="Nostr Map" />
  <meta property="og:locale"      content="fr_FR" />
  <meta name="twitter:card"       content="summary_large_image" />
  <meta name="twitter:title"      content="' . $title . '" />
  <meta name="twitter:description"content="' . $bio . '" />
  <meta name="twitter:image"      content="' . htmlspecialchars($ogImage, ENT_QUOTES) . '" />
  <meta name="twitter:image:alt"  content="' . $imageAlt . '" />
  <link rel="canonical"           href="' . $url . '" />
  <script type="application/ld+json">' . $ldJson . '</script>';

// Remplacer aussi le title générique
$html = preg_replace(
    '#<title>[^<]*</title>#',
    '<title>' . $title . '</title>',
    $html,
    1
);

// Remplacer la meta description générique si elle existe
$html = preg_replace(
    '#<meta name="description"[^>]*>#',
    '<meta name="description" content="' . $bio . '" />',
    $html,
    1
);

// Remplacer le noindex générique de p.html par la directive indexable du profil.
$html = preg_replace(
    '#<meta name="robots"[^>]*>#',
    '<meta name="robots" content="index, follow" />',
    $html,
    1
);

// Injecter les OG tags avant </head>
$html = str_replace('</head>', $ogTags . "\n</head>", $html);

// ─── Données SSR pour le JS (évite un aller-retour API) ──────────────────────
// Miroir exact de ce que retourne /api/profile.php en accès public (non-owner)
$linksStmt = $db->prepare(
    'SELECT platform, display_handle, url, verified, verified_at
     FROM social_links WHERE npub = ? ORDER BY verified DESC, id ASC'
);
$linksStmt->execute([$p['npub']]);
$publicLinks = $linksStmt->fetchAll(PDO::FETCH_ASSOC);


$ssrData = json_encode([
    'profile' => [
        'npub'             => $p['npub'],
        'slug'             => $p['slug'],
        'cached_name'      => $p['cached_name'],
        'cached_avatar'    => $p['cached_avatar'],
        'cached_bio'       => $p['cached_bio'],
        'cached_nip05'     => $p['cached_nip05'],
        'nostr_created_at' => $p['nostr_created_at'] ?? null,
        'nostr_followers'  => isset($p['nostr_followers']) ? (int)$p['nostr_followers'] : null,
        'nostr_posts'      => isset($p['nostr_posts'])     ? (int)$p['nostr_posts']     : null,
        'community_added'  => (bool)($p['community_added'] ?? false),
    ],
    'links' => $publicLinks,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

$dataScript = '<script id="profile-data" type="application/json">' . $ssrData . '</script>';
$html = str_replace('</body>', $dataScript . "\n</body>", $html);

echo $html;
