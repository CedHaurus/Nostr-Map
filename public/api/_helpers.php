<?php
/**
 * _helpers.php — Nostr Map
 * Fonctions utilitaires : JWT, vérification signature Schnorr secp256k1 (BIP-340),
 * helpers Nostr event.
 */

declare(strict_types=1);

require_once '/var/www/config/db.php';

// ─── CONSTANTES secp256k1 ────────────────────────────────────────────────────

define('SECP256K1_P',  gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEFFFFFC2F', 16));
define('SECP256K1_N',  gmp_init('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFEBAAEDCE6AF48A03BBFD25E8CD0364141', 16));
define('SECP256K1_GX', gmp_init('79BE667EF9DCBBAC55A06295CE870B07029BFCDB2DCE28D959F2815B16F81798', 16));
define('SECP256K1_GY', gmp_init('483ADA7726A3C4655DA4FBFC0E1108A8FD17B448A68554199C47D08FFB10D4B8', 16));

// ─── JWT ─────────────────────────────────────────────────────────────────────

function jwtEncode(array $payload): string {
    $secret = getenv('JWT_SECRET');
    if (!$secret) throw new \RuntimeException('JWT_SECRET non configuré');
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));
    return "{$header}.{$payload}.{$sig}";
}

function jwtDecode(string $token): ?array {
    $secret = getenv('JWT_SECRET');
    if (!$secret) throw new \RuntimeException('JWT_SECRET non configuré');
    $parts  = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

    if (!hash_equals($expected, $sig)) return null;

    $data = json_decode(base64url_decode($payload), true);
    if (!$data || (isset($data['exp']) && $data['exp'] < time())) return null;

    return $data;
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

// Hache une IP avec HMAC-SHA256 + sel secret (RGPD : données personnelles pseudonymisées)
// Utiliser systématiquement pour tout stockage d'IP, sauf blocked_ips (gestion admin).
function hashIp(string $ip): string {
    $salt = getenv('JWT_SECRET') ?: 'nostrmap-ip-salt';
    return 'h:' . hash_hmac('sha256', $ip, $salt);
}

function getBearerToken(): ?string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
        return $m[1];
    }
    return null;
}

function requireAuth(): array {
    $token = getBearerToken();
    if (!$token) jsonError('Authentification requise', 401);

    $data = jwtDecode($token);
    if (!$data) jsonError('Token invalide ou expiré', 401);

    return $data;
}

// ─── RÉPONSES JSON ───────────────────────────────────────────────────────────

function jsonOk(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $code = 400): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ─── CORS / HEADERS ──────────────────────────────────────────────────────────

function setCorsHeaders(): void {
    $appUrl = getenv('APP_URL') ?: 'https://nostrmap.fr';
    header("Access-Control-Allow-Origin: {$appUrl}");
    header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

// ─── NOSTR EVENT ─────────────────────────────────────────────────────────────

/**
 * Calcule l'ID canonique d'un event Nostr (SHA-256 de la sérialisation).
 */
function computeEventId(array $event): string {
    $data = json_encode([
        0,
        $event['pubkey'],
        (int) $event['created_at'],
        (int) $event['kind'],
        $event['tags'],
        $event['content'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    return hash('sha256', $data);
}

/**
 * Vérifie qu'un event Nostr est valide :
 *  - ID correct
 *  - Signature Schnorr valide (BIP-340)
 *  - created_at récent (< $maxAge secondes)
 */
function verifyNostrEvent(array $event, int $maxAge = 60): bool {
    // Champs obligatoires
    foreach (['id', 'pubkey', 'created_at', 'kind', 'tags', 'content', 'sig'] as $f) {
        if (!isset($event[$f])) return false;
    }

    // Vérifier le format hex
    if (!isHex($event['id'], 64))     return false;
    if (!isHex($event['pubkey'], 64)) return false;
    if (!isHex($event['sig'], 128))   return false;

    // Vérifier l'ID
    if (computeEventId($event) !== $event['id']) return false;

    // Vérifier la fraîcheur
    $age = abs(time() - (int) $event['created_at']);
    if ($age > $maxAge) return false;

    // Vérifier la signature
    return verifySchnorrSignature($event['pubkey'], $event['id'], $event['sig']);
}

function isHex(string $str, int $len): bool {
    return strlen($str) === $len && ctype_xdigit($str);
}

// ─── SCHNORR BIP-340 ─────────────────────────────────────────────────────────
// Implémentation pure PHP avec extension GMP.
// Référence : https://github.com/bitcoin/bips/blob/master/bip-0340.mediawiki

/**
 * Vérifie une signature Schnorr BIP-340.
 *
 * @param string $pubkeyHex  Clé publique 32 octets (x-only), hex 64 chars
 * @param string $msgHex     Message 32 octets (event ID), hex 64 chars
 * @param string $sigHex     Signature 64 octets (r||s), hex 128 chars
 */
function verifySchnorrSignature(string $pubkeyHex, string $msgHex, string $sigHex): bool {
    try {
        $p = SECP256K1_P;
        $n = SECP256K1_N;

        // Décoder les entrées
        $Px = gmp_init($pubkeyHex, 16);
        $r  = gmp_import(hex2bin(substr($sigHex, 0, 64)));
        $s  = gmp_import(hex2bin(substr($sigHex, 64, 64)));
        $m  = hex2bin($msgHex);

        // Vérifier les bornes
        if (gmp_cmp($Px, $p) >= 0) return false;
        if (gmp_cmp($r, $p) >= 0)  return false;
        if (gmp_cmp($s, $n) >= 0)  return false;

        // Lever le point P depuis x (y pair pour BIP-340)
        $P = secp256k1_lift_x($Px);
        if ($P === null) return false;

        // e = int(hashBIP0340/challenge(bytes(r) || bytes(P) || m)) mod n
        $rBytes  = str_pad(gmp_export($r), 32, "\x00", STR_PAD_LEFT);
        $PxBytes = str_pad(gmp_export($Px), 32, "\x00", STR_PAD_LEFT);
        $eHash   = secp256k1_tagged_hash('BIP0340/challenge', $rBytes . $PxBytes . $m);
        $e       = gmp_mod(gmp_import($eHash), $n);

        // R = s*G - e*P
        $G  = ['x' => SECP256K1_GX, 'y' => SECP256K1_GY];
        $sG = secp256k1_point_mul($s, $G);
        if ($sG === null) return false;

        // -e*P = e*(−P), et −P = (Px, p-Py)
        $negP = ['x' => $P['x'], 'y' => gmp_mod(gmp_sub($p, $P['y']), $p)];
        $eP   = secp256k1_point_mul($e, $negP);

        if ($eP === null) {
            $R = $sG;
        } else {
            $R = secp256k1_point_add($sG, $eP);
        }

        if ($R === null) return false;

        // R doit avoir y pair et x = r
        if (gmp_mod($R['y'], gmp_init(2)) != 0) return false;
        if (gmp_cmp($R['x'], $r) !== 0) return false;

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Tagged hash BIP-340 : SHA256(SHA256(tag) || SHA256(tag) || msg)
 */
function secp256k1_tagged_hash(string $tag, string $msg): string {
    $tagHash = hash('sha256', $tag, true);
    return hash('sha256', $tagHash . $tagHash . $msg, true);
}

/**
 * Reconstruit un point secp256k1 depuis sa coordonnée x (y pair, BIP-340).
 * y² = x³ + 7 (mod p)
 * Comme p ≡ 3 mod 4 : y = x^((p+1)/4) mod p
 */
function secp256k1_lift_x(\GMP $x): ?array {
    $p = SECP256K1_P;
    if (gmp_cmp($x, $p) >= 0) return null;

    // y² = x³ + 7
    $x3     = gmp_mod(gmp_pow($x, 3), $p);
    $y2     = gmp_mod(gmp_add($x3, gmp_init(7)), $p);

    // y = y2^((p+1)/4) mod p
    $exp    = gmp_div(gmp_add($p, gmp_init(1)), gmp_init(4));
    $y      = gmp_powm($y2, $exp, $p);

    // Vérifier que y² ≡ y2
    if (gmp_cmp(gmp_mod(gmp_mul($y, $y), $p), $y2) !== 0) return null;

    // Choisir y pair
    if (gmp_mod($y, gmp_init(2)) != 0) {
        $y = gmp_mod(gmp_sub($p, $y), $p);
    }

    return ['x' => $x, 'y' => $y];
}

/**
 * Addition de deux points sur secp256k1.
 * Retourne null si l'un des points est l'identité ou si P = -Q.
 */
function secp256k1_point_add(?array $P, ?array $Q): ?array {
    if ($P === null) return $Q;
    if ($Q === null) return $P;

    $p  = SECP256K1_P;
    $Px = $P['x']; $Py = $P['y'];
    $Qx = $Q['x']; $Qy = $Q['y'];

    if (gmp_cmp($Px, $Qx) === 0) {
        if (gmp_cmp($Py, $Qy) !== 0) return null; // P = -Q → infini
        return secp256k1_point_double($P);
    }

    // lambda = (Qy - Py) / (Qx - Px) mod p
    $num    = gmp_mod(gmp_sub($Qy, $Py), $p);
    $den    = gmp_mod(gmp_sub($Qx, $Px), $p);
    $lambda = gmp_mod(gmp_mul($num, gmp_invert($den, $p)), $p);

    $Rx = gmp_mod(gmp_sub(gmp_sub(gmp_mul($lambda, $lambda), $Px), $Qx), $p);
    $Ry = gmp_mod(gmp_sub(gmp_mul($lambda, gmp_sub($Px, $Rx)), $Py), $p);

    return ['x' => $Rx, 'y' => $Ry];
}

/**
 * Doublement d'un point sur secp256k1.
 */
function secp256k1_point_double(array $P): ?array {
    $p  = SECP256K1_P;
    $Px = $P['x']; $Py = $P['y'];

    if (gmp_cmp($Py, gmp_init(0)) === 0) return null;

    // lambda = (3*Px²) / (2*Py) mod p
    $num    = gmp_mod(gmp_mul(gmp_init(3), gmp_mul($Px, $Px)), $p);
    $den    = gmp_mod(gmp_mul(gmp_init(2), $Py), $p);
    $lambda = gmp_mod(gmp_mul($num, gmp_invert($den, $p)), $p);

    $Rx = gmp_mod(gmp_sub(gmp_mul($lambda, $lambda), gmp_mul(gmp_init(2), $Px)), $p);
    $Ry = gmp_mod(gmp_sub(gmp_mul($lambda, gmp_sub($Px, $Rx)), $Py), $p);

    return ['x' => $Rx, 'y' => $Ry];
}

/**
 * Multiplication scalaire k*P par double-and-add.
 */
function secp256k1_point_mul(\GMP $k, array $G): ?array {
    $n = SECP256K1_N;
    $k = gmp_mod($k, $n);
    if (gmp_cmp($k, gmp_init(0)) === 0) return null;

    $R  = null;
    $kv = $k;

    while (gmp_cmp($kv, gmp_init(0)) > 0) {
        if (gmp_mod($kv, gmp_init(2)) == 1) {
            $R = secp256k1_point_add($R, $G);
        }
        $G  = secp256k1_point_double($G);
        $kv = gmp_div($kv, gmp_init(2));
    }

    return $R;
}

// ─── NPUB / HEX ──────────────────────────────────────────────────────────────

/**
 * Convertit un pubkey hex en npub (bech32 nostr).
 */
function hexToNpub(string $hex): string {
    $data    = hex2bin($hex);
    $encoded = bech32_encode('npub', convertbits(str_split($data), 8, 5, true));
    return $encoded;
}

/**
 * Convertit un npub en pubkey hex.
 */
function npubToHex(string $npub): ?string {
    [$hrp, $data] = bech32_decode($npub);
    if ($hrp !== 'npub') return null;
    $bytes = convertbits($data, 5, 8, false);
    return bin2hex(implode('', array_map('chr', $bytes)));
}

// ─── BECH32 ──────────────────────────────────────────────────────────────────

function bech32_polymod(array $values): int {
    $GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    $chk = 1;
    foreach ($values as $v) {
        $b   = $chk >> 25;
        $chk = (($chk & 0x1ffffff) << 5) ^ $v;
        for ($i = 0; $i < 5; $i++) {
            if (($b >> $i) & 1) $chk ^= $GEN[$i];
        }
    }
    return $chk;
}

function bech32_hrpExpand(string $hrp): array {
    $ret = [];
    for ($i = 0; $i < strlen($hrp); $i++) $ret[] = ord($hrp[$i]) >> 5;
    $ret[] = 0;
    for ($i = 0; $i < strlen($hrp); $i++) $ret[] = ord($hrp[$i]) & 31;
    return $ret;
}

function bech32_encode(string $hrp, array $data): string {
    $CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    $combined = array_merge($data, [0,0,0,0,0,0]);
    $chk      = bech32_polymod(array_merge(bech32_hrpExpand($hrp), $combined)) ^ 1;
    $checksum = [];
    for ($i = 0; $i < 6; $i++) $checksum[] = ($chk >> (5 * (5 - $i))) & 31;
    $enc = $hrp . '1';
    foreach (array_merge($data, $checksum) as $v) $enc .= $CHARSET[$v];
    return $enc;
}

function bech32_decode(string $bechString): array {
    $CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    $pos = strrpos($bechString, '1');
    if ($pos === false || $pos < 1 || $pos + 7 > strlen($bechString)) {
        throw new \InvalidArgumentException('Invalid bech32 string');
    }
    $hrp  = strtolower(substr($bechString, 0, $pos));
    $data = [];
    for ($i = $pos + 1; $i < strlen($bechString); $i++) {
        $d = strpos($CHARSET, strtolower($bechString[$i]));
        if ($d === false) throw new \InvalidArgumentException('Invalid char');
        $data[] = $d;
    }
    return [$hrp, array_slice($data, 0, -6)];
}

function convertbits(array $data, int $fromBits, int $toBits, bool $pad): array {
    $acc    = 0;
    $bits   = 0;
    $ret    = [];
    $maxv   = (1 << $toBits) - 1;
    foreach ($data as $v) {
        $v    = is_string($v) ? ord($v) : $v;
        $acc  = ($acc << $fromBits) | $v;
        $bits += $fromBits;
        while ($bits >= $toBits) {
            $bits -= $toBits;
            $ret[] = ($acc >> $bits) & $maxv;
        }
    }
    if ($pad && $bits > 0) {
        $ret[] = ($acc << ($toBits - $bits)) & $maxv;
    }
    return $ret;
}

// ─── LOG ACTIVITÉ UTILISATEUR ────────────────────────────────────────────────

function logUserActivity(string $npub, string $action, ?string $targetType = null, ?string $targetId = null, ?string $details = null): void {
    try {
        $db = getDB();
        $db->prepare(
            'INSERT INTO user_activity (npub, action, target_type, target_id, details)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$npub, $action, $targetType, $targetId, $details]);
    } catch (Throwable) { /* silencieux */ }
}

// ─── GÉNÉRATION DE SLUG ──────────────────────────────────────────────────────

function generateSlug(string $npub, ?string $nip05 = null): string {
    $db = getDB();

    // Essayer d'utiliser la partie locale du NIP-05
    if ($nip05 && preg_match('/^([a-z0-9_.-]+)@/i', $nip05, $m)) {
        $base = strtolower(preg_replace('/[^a-z0-9_-]/', '', $m[1]));
        if (strlen($base) >= 3) {
            $slug = substr($base, 0, 30);
            if (isSlugAvailable($slug, $db)) return $slug;
            // Essayer avec suffixe
            for ($i = 2; $i <= 99; $i++) {
                $try = $slug . $i;
                if (isSlugAvailable($try, $db)) return $try;
            }
        }
    }

    // Fallback : 12 chars depuis npub (après "npub1")
    $base = strtolower(preg_replace('/[^a-z0-9]/', '', substr($npub, 5, 12)));
    if (strlen($base) < 6) $base = bin2hex(random_bytes(4));
    $slug = substr($base, 0, 12);

    if (isSlugAvailable($slug, $db)) return $slug;
    for ($i = 2; $i <= 999; $i++) {
        $try = $slug . $i;
        if (isSlugAvailable($try, $db)) return $try;
    }

    return substr($base, 0, 8) . bin2hex(random_bytes(2));
}

function isSlugAvailable(string $slug, PDO $db): bool {
    $stmt = $db->prepare('SELECT 1 FROM profiles WHERE slug = ?');
    $stmt->execute([$slug]);
    return $stmt->fetchColumn() === false;
}

// ─── CHALLENGE RS ────────────────────────────────────────────────────────────

function generateChallenge(string $npub): string {
    $short  = substr($npub, 5, 8);
    $random = substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(6))), 0, 6);
    return "nostrmap:{$short}:{$random}";
}

// ─── PRIMAL CACHE ────────────────────────────────────────────────────────────

/**
 * Récupère profil (kind:0) + stats (kind:10000105) depuis cache2.primal.net.
 * Retourne [meta|null, stats|null]
 */
function fetchPrimalData(string $hex): array {
    $ch = curl_init('https://cache2.primal.net/api');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['user_profile', ['pubkey' => $hex]]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERAGENT      => 'NostrMap-Cache/1.0 (+https://nostrmap.fr)',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if (!$body || $code !== 200) return [null, null];
    $events = json_decode($body, true);
    if (!is_array($events)) return [null, null];

    $meta = null; $stats = null; $best = null;
    foreach ($events as $event) {
        if (!is_array($event)) continue;
        if (($event['kind'] ?? -1) === 0) {
            if (!$best || ($event['created_at'] ?? 0) > ($best['created_at'] ?? 0)) {
                $best = $event;
            }
        }
        if (($event['kind'] ?? -1) === 10000105) {
            $s = is_string($event['content'] ?? null) ? json_decode($event['content'], true) : null;
            if (is_array($s)) {
                $stats = [
                    'followers' => max(0, (int)($s['followers_count'] ?? 0)),
                    'posts'     => max(0, (int)($s['note_count']      ?? 0)),
                    'joined_at' => max(0, (int)($s['time_joined']     ?? 0)),
                ];
            }
        }
    }
    if ($best) {
        $c = is_string($best['content'] ?? null) ? json_decode($best['content'], true) : null;
        if (is_array($c)) {
            $meta = [
                'name'       => $c['display_name'] ?? $c['name'] ?? null,
                'nostr_name' => $c['name'] ?? null,   // handle Nostr brut, pour la recherche
                'picture'    => $c['picture'] ?? null,
                'about'      => $c['about']   ?? null,
                'nip05'      => $c['nip05']   ?? null,
            ];
        }
    }
    return [$meta, $stats];
}

/**
 * Fallback metadata depuis le cache relay (relay_cache.json).
 * Ce cache est alimenté par relay_meta_host.py (cron sur le host).
 * Retourne le même format que le champ $meta de fetchPrimalData, ou null.
 */
function fetchRelayMeta(string $hex): ?array {
    static $cache = null;
    $cacheFile = '/var/www/html/storage/relay_cache.json';
    if ($cache === null) {
        if (!file_exists($cacheFile)) return null;
        $raw = file_get_contents($cacheFile);
        $cache = $raw ? (json_decode($raw, true) ?? []) : [];
    }
    if (!isset($cache[$hex])) return null;
    $d = $cache[$hex];
    return [
        'name'       => $d['name']       ?? null,
        'nostr_name' => $d['nostr_name'] ?? null,
        'picture'    => $d['picture']    ?? null,
        'about'      => $d['about']      ?? null,
        'nip05'      => $d['nip05']      ?? null,
    ];
}

/**
 * Ajoute un hex dans la queue relay si Primal n'a pas de métadonnées.
 * relay_meta_host.py (cron host) traitera la queue et alimentera relay_cache.json.
 */
function queueRelayFetch(string $hex): void {
    $queueFile = '/var/www/html/storage/relay_queue.json';
    $queue = file_exists($queueFile)
        ? (json_decode(file_get_contents($queueFile), true) ?? [])
        : [];
    if (!in_array($hex, $queue, true)) {
        $queue[] = $hex;
        file_put_contents($queueFile, json_encode($queue), LOCK_EX);
    }
}

/**
 * Applique un tableau $meta aux colonnes d'un profil.
 * Factorisé pour éviter la duplication entre warmProfileCache et le cron.
 */
function applyMetaToSet(array $meta, bool $nameLocked, array &$set, array &$params): void {
    if (!empty($meta['name']) && !$nameLocked) { array_unshift($set, 'cached_name = ?');   array_unshift($params, mb_substr($meta['name'],       0, 100)); }
    if (!empty($meta['nostr_name']))            { array_unshift($set, 'nostr_name = ?');    array_unshift($params, mb_substr($meta['nostr_name'], 0, 100)); }
    if (!empty($meta['picture']))               { array_unshift($set, 'cached_avatar = ?'); array_unshift($params, mb_substr($meta['picture'],    0, 500)); }
    if (!empty($meta['about']))                 { array_unshift($set, 'cached_bio = ?');    array_unshift($params, mb_substr($meta['about'],      0, 2000)); }
    if (!empty($meta['nip05']))                 { array_unshift($set, 'cached_nip05 = ?');  array_unshift($params, mb_substr($meta['nip05'],      0, 200)); }
}

/**
 * Récupère et sauvegarde immédiatement les métadonnées Nostr d'un profil.
 * À appeler juste après la création d'une fiche.
 */
function warmProfileCache(string $npub, PDO $db): void {
    $hex = npubToHex($npub);
    if (!$hex) return;

    // Ne pas écraser le nom si l'utilisateur l'a défini manuellement
    $lockRow = $db->prepare('SELECT display_name_updated_at FROM profiles WHERE npub = ?');
    $lockRow->execute([$npub]);
    $nameLocked = (bool) $lockRow->fetchColumn();

    [$meta, $stats] = fetchPrimalData($hex);

    // Fallback : lire le cache relay (alimenté par relay_meta_host.py sur le host)
    // Si cache vide aussi, mettre en queue pour fetch prochain
    if (!$meta) {
        $meta = fetchRelayMeta($hex);
        if (!$meta) queueRelayFetch($hex);
    }

    $set = ['last_fetch = NOW()']; $params = [];
    if ($meta) {
        applyMetaToSet($meta, $nameLocked, $set, $params);
    }
    if ($stats) {
        if (!empty($stats['joined_at'])) { $set[] = 'nostr_created_at = ?'; $params[] = $stats['joined_at']; }
        $set[] = 'nostr_followers = ?';  $params[] = $stats['followers'];
        $set[] = 'nostr_posts = ?';      $params[] = $stats['posts'];
        $set[] = 'last_stats_fetch = NOW()';
    }
    $params[] = $npub;
    $db->prepare('UPDATE profiles SET ' . implode(', ', $set) . ' WHERE npub = ?')->execute($params);
}
