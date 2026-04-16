<?php
/**
 * /api/profile.php
 *
 * GET  ?slug=[slug]                        → profil public + liens
 * POST (auth)                              → mise à jour cache profil
 * POST ?action=add_link (auth)             → ajout lien RS
 * DELETE ?action=delete_link&id=N (auth)  → suppression lien
 */

declare(strict_types=1);

require_once __DIR__ . '/_helpers.php';

setCorsHeaders();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ─── GET : profil public ──────────────────────────────────────────────────────

if ($method === 'GET') {
    $slug = trim($_GET['slug'] ?? '');
    if (!$slug || !preg_match('/^[a-z0-9_-]+$/i', $slug)) {
        jsonError('Slug invalide');
    }

    $db   = getDB();
    $stmt = $db->prepare(
        'SELECT npub, slug, cached_name, cached_avatar, cached_bio, cached_nip05,
                nostr_created_at, nostr_followers, nostr_posts,
                (last_login IS NULL) AS community_added
         FROM profiles
         WHERE slug = ? AND status = "active"'
    );
    $stmt->execute([$slug]);
    $profile = $stmt->fetch();

    if (!$profile) jsonError('Profil introuvable', 404);

    // Si l'utilisateur est authentifié et consulte son propre profil, inclure les challenges
    $isOwner = false;
    $token   = getBearerToken();
    if ($token) {
        $authData = jwtDecode($token);
        if ($authData && ($authData['sub'] ?? '') === $profile['npub']) {
            $isOwner = true;
        }
    }

    $linkCols = $isOwner
        ? 'id, platform, display_handle, url, challenge, verified, verified_at'
        : 'id, platform, display_handle, url, verified, verified_at';

    $stmt = $db->prepare(
        "SELECT {$linkCols} FROM social_links WHERE npub = ? ORDER BY verified DESC, id ASC"
    );
    $stmt->execute([$profile['npub']]);
    $links = $stmt->fetchAll();

    // En public : masquer l'URL des liens non vérifiés
    if (!$isOwner) {
        foreach ($links as &$link) {
            if (!$link['verified']) {
                $link['url'] = null;
            }
        }
        unset($link);
    }

    jsonOk(['profile' => $profile, 'links' => $links]);
}

// ─── POST : mise à jour cache ou ajout lien ───────────────────────────────────

if ($method === 'POST') {
    $auth = requireAuth();
    $npub = $auth['sub'];

    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $db   = getDB();

    // Étape 1 : générer le code challenge (sans URL encore)
    if ($action === 'generate_challenge') {
        $platform = strtolower(trim($body['platform'] ?? ''));
        $handle   = trim($body['display_handle'] ?? '');

        $allowed = ['x', 'mastodon', 'bluesky', 'youtube'];
        if (!in_array($platform, $allowed)) jsonError('Plateforme non supportée');

        // Limite : 10 liens par profil
        $count = $db->prepare('SELECT COUNT(*) FROM social_links WHERE npub = ?');
        $count->execute([$npub]);
        if ($count->fetchColumn() >= 10) jsonError('Limite de 10 liens atteinte');

        $challenge = generateChallenge($npub);
        $stmt = $db->prepare(
            'INSERT INTO social_links (npub, platform, display_handle, url, challenge)
             VALUES (?, ?, ?, NULL, ?)'
        );
        $stmt->execute([$npub, $platform, $handle ?: null, $challenge]);
        $linkId = (int) $db->lastInsertId();

        logUserActivity($npub, 'add_link', 'link', (string)$linkId, "Plateforme: {$platform}");
        jsonOk(['link_id' => $linkId, 'challenge' => $challenge]);
    }

    // Étape 2 : enregistrer l'URL avant vérification
    if ($action === 'set_url') {
        $linkId = (int)($body['link_id'] ?? 0);
        $url    = trim($body['url'] ?? '');

        if ($linkId <= 0) jsonError('ID invalide');
        if (!filter_var($url, FILTER_VALIDATE_URL)) jsonError('URL invalide');
        if (strlen($url) > 500) jsonError('URL trop longue');

        // Vérifier que le lien appartient à l'utilisateur et récupérer la plateforme
        $check = $db->prepare('SELECT id, platform FROM social_links WHERE id = ? AND npub = ?');
        $check->execute([$linkId, $npub]);
        $linkRow = $check->fetch();
        if (!$linkRow) jsonError('Lien introuvable', 404);

        // Allowlist : l'URL doit correspondre au domaine attendu de la plateforme
        $urlHost = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $urlScheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
        if ($urlScheme !== 'https') jsonError('Seules les URLs HTTPS sont autorisées');

        $platformPatterns = [
            'x'        => '/^(www\.)?(x\.com|twitter\.com)$/',
            'bluesky'  => '/^(www\.)?bsky\.app$/',
            'youtube'  => '/^(www\.)?(youtube\.com|youtu\.be)$/',
            'mastodon' => null, // Instances multiples — schéma HTTPS suffit
        ];
        $pattern = $platformPatterns[$linkRow['platform']] ?? false;
        if ($pattern === false) jsonError('Plateforme non reconnue');
        if ($pattern !== null && !preg_match($pattern, $urlHost)) {
            jsonError('URL incompatible avec la plateforme ' . $linkRow['platform']);
        }

        // Vérifier unicité URL
        $dup = $db->prepare('SELECT id FROM social_links WHERE npub = ? AND url = ? AND id != ?');
        $dup->execute([$npub, $url, $linkId]);
        if ($dup->fetch()) jsonError('Ce lien existe déjà');

        $db->prepare('UPDATE social_links SET url = ? WHERE id = ?')->execute([$url, $linkId]);
        jsonOk(['success' => true]);
    }

    // (legacy) Ajouter un lien RS en une fois
    if ($action === 'add_link') {
        $platform = strtolower(trim($body['platform'] ?? ''));
        $handle   = trim($body['display_handle'] ?? '');
        $url      = trim($body['url'] ?? '');

        $allowed = ['x', 'mastodon', 'bluesky', 'youtube'];
        if (!in_array($platform, $allowed)) jsonError('Plateforme non supportée');
        if (!filter_var($url, FILTER_VALIDATE_URL)) jsonError('URL invalide');
        if (strlen($url) > 500) jsonError('URL trop longue');

        // HTTPS obligatoire
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') jsonError('Seules les URLs HTTPS sont autorisées');

        // Allowlist : l'URL doit correspondre au domaine attendu de la plateforme
        $urlHostLegacy = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        $platformPatternsLegacy = [
            'x'        => '/^(www\.)?(x\.com|twitter\.com)$/',
            'bluesky'  => '/^(www\.)?bsky\.app$/',
            'youtube'  => '/^(www\.)?(youtube\.com|youtu\.be)$/',
            'mastodon' => null, // Instances multiples — schéma HTTPS suffit
        ];
        $patternLegacy = $platformPatternsLegacy[$platform] ?? false;
        if ($patternLegacy === false) jsonError('Plateforme non reconnue');
        if ($patternLegacy !== null && !preg_match($patternLegacy, $urlHostLegacy)) {
            jsonError('URL incompatible avec la plateforme ' . $platform);
        }

        $count = $db->prepare('SELECT COUNT(*) FROM social_links WHERE npub = ?');
        $count->execute([$npub]);
        if ($count->fetchColumn() >= 10) jsonError('Limite de 10 liens atteinte');

        $dup = $db->prepare('SELECT id FROM social_links WHERE npub = ? AND url = ?');
        $dup->execute([$npub, $url]);
        if ($dup->fetch()) jsonError('Ce lien existe déjà');

        $challenge = generateChallenge($npub);
        $stmt = $db->prepare(
            'INSERT INTO social_links (npub, platform, display_handle, url, challenge)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$npub, $platform, $handle ?: null, $url, $challenge]);
        $linkId = (int) $db->lastInsertId();

        logUserActivity($npub, 'add_link', 'link', (string)$linkId, "Plateforme: {$platform}, URL: " . substr($url, 0, 100));
        jsonOk(['link_id' => $linkId, 'challenge' => $challenge]);
    }

    // Mise à jour du cache profil (appelé depuis nostr.js après fetch des relais)
    $allowed = ['cached_name', 'cached_avatar', 'cached_bio', 'cached_nip05',
                'nostr_created_at', 'nostr_followers', 'nostr_posts'];
    $update  = [];
    $params  = [];
    $statsFields = ['nostr_created_at', 'nostr_followers', 'nostr_posts'];
    $hasStatsUpdate = false;

    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $val = $body[$field];
            if ($val !== null) {
                // Champs numériques
            if (in_array($field, $statsFields, true)) {
                $val = max(0, (int)$val);
                $hasStatsUpdate = true;
            } else {
                $val = mb_substr((string)$val, 0, match($field) {
                    'cached_name'   => 100,
                    'cached_avatar' => 500,
                    'cached_nip05'  => 200,
                    default         => 2000,
                });
            }
            }
            $update[] = "{$field} = ?";
            $params[]  = $val;
        }
    }

    if (empty($update)) jsonError('Aucun champ à mettre à jour');

    // Si nouveau nip05, re-générer le slug si meilleur
    $newSlug = null;
    if (isset($body['cached_nip05']) && $body['cached_nip05']) {
        $stmt = $db->prepare('SELECT slug, cached_nip05 FROM profiles WHERE npub = ?');
        $stmt->execute([$npub]);
        $row = $stmt->fetch();
        // Mettre à jour le slug si pas encore de nip05 en base
        if ($row && !$row['cached_nip05']) {
            $newSlug = generateSlug($npub, $body['cached_nip05']);
            $update[] = 'slug = ?';
            $params[]  = $newSlug;
        }
    }

    if ($hasStatsUpdate) {
        $update[] = 'last_stats_fetch = NOW()';
    }

    $update[]  = 'last_fetch = NOW()';
    $params[]  = $npub;

    $db->prepare('UPDATE profiles SET ' . implode(', ', $update) . ' WHERE npub = ?')
       ->execute($params);

    logUserActivity($npub, 'update_profile', 'profile', $npub, 'Champs: ' . implode(', ', array_intersect($allowed, array_keys($body))));

    $response = ['success' => true];
    // Si le slug a changé, émettre un nouveau JWT pour que le client le reflète
    if ($newSlug) {
        $response['newToken'] = jwtEncode([
            'sub'  => $npub,
            'slug' => $newSlug,
            'iat'  => time(),
            'exp'  => time() + 86400,
        ]);
        $response['newSlug'] = $newSlug;
    }
    jsonOk($response);
}

// ─── DELETE : supprimer un lien ───────────────────────────────────────────────

if ($method === 'DELETE') {
    $auth   = requireAuth();
    $npub   = $auth['sub'];
    $linkId = (int)($_GET['id'] ?? 0);

    if ($linkId <= 0) jsonError('ID invalide');

    $db = getDB();

    // Vérifier que le lien appartient bien à l'utilisateur
    $stmt = $db->prepare('SELECT id FROM social_links WHERE id = ? AND npub = ?');
    $stmt->execute([$linkId, $npub]);
    if (!$stmt->fetch()) jsonError('Lien introuvable', 404);

    $db->prepare('DELETE FROM social_links WHERE id = ?')->execute([$linkId]);

    logUserActivity($npub, 'delete_link', 'link', (string)$linkId);
    jsonOk(['success' => true]);
}

jsonError('Méthode non autorisée', 405);
