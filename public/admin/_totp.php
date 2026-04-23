<?php
/**
 * _totp.php — Implémentation TOTP (RFC 6238) sans dépendance externe
 * Algorithme : HMAC-SHA1, 6 chiffres, période 30s
 */

declare(strict_types=1);

// ─── Base32 ───────────────────────────────────────────────────────────────────

function base32Encode(string $bytes): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $out   = '';
    $buf   = 0;
    $bits  = 0;
    foreach (str_split($bytes) as $byte) {
        $buf   = ($buf << 8) | ord($byte);
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $out  .= $alpha[($buf >> $bits) & 0x1f];
        }
    }
    if ($bits > 0) {
        $out .= $alpha[($buf << (5 - $bits)) & 0x1f];
    }
    return $out;
}

function base32Decode(string $s): string
{
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $s     = strtoupper(preg_replace('/[\s=]/', '', $s));
    $out   = '';
    $buf   = 0;
    $bits  = 0;
    foreach (str_split($s) as $c) {
        $val = strpos($alpha, $c);
        if ($val === false) continue;
        $buf   = ($buf << 5) | $val;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $out  .= chr(($buf >> $bits) & 0xff);
        }
    }
    return $out;
}

// ─── TOTP ─────────────────────────────────────────────────────────────────────

function totpGenerateSecret(): string
{
    return base32Encode(random_bytes(20));
}

function totpCode(string $secret, int $offset = 0): string
{
    $counter = (int)floor(time() / 30) + $offset;
    $key     = base32Decode($secret);
    $msg     = pack('J', $counter); // unsigned 64-bit big-endian
    $hash    = hash_hmac('sha1', $msg, $key, true);
    $ofs     = ord($hash[19]) & 0x0f;
    $code    = ((ord($hash[$ofs])   & 0x7f) << 24)
             | ((ord($hash[$ofs+1]) & 0xff) << 16)
             | ((ord($hash[$ofs+2]) & 0xff) <<  8)
             |  (ord($hash[$ofs+3]) & 0xff);
    return str_pad((string)($code % 1_000_000), 6, '0', STR_PAD_LEFT);
}

function totpVerify(string $secret, string $code): bool
{
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    // Fenêtre ±1 step (tolère décalage horloge ±30s)
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(totpCode($secret, $i), $code)) return true;
    }
    return false;
}

function totpUri(string $secret, string $username, string $issuer = 'Nostr Map Admin'): string
{
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $username)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode($issuer)
         . '&algorithm=SHA1&digits=6&period=30';
}

// ─── Chiffrement des secrets TOTP ─────────────────────────────────────────────

function totpHasDedicatedKey(): bool
{
    return trim((string)(getenv('TOTP_ENCRYPTION_KEY') ?: '')) !== '';
}

function totpKeyCandidates(): array
{
    $candidates = [];

    $totpKey = trim((string)(getenv('TOTP_ENCRYPTION_KEY') ?: ''));
    if ($totpKey !== '') {
        $candidates['totp'] = hash('sha256', $totpKey, true);
    }

    $jwtKey = trim((string)(getenv('JWT_SECRET') ?: ''));
    if ($jwtKey !== '') {
        $jwtHash = hash('sha256', $jwtKey, true);
        if (!in_array($jwtHash, $candidates, true)) {
            $candidates['jwt'] = $jwtHash;
        }
    }

    if ($candidates === []) {
        throw new \RuntimeException('Aucune clé de chiffrement disponible — TOTP_ENCRYPTION_KEY ou JWT_SECRET requis');
    }

    return $candidates;
}

function totpEncryptWithKey(string $plainSecret, string $key): string
{
    $iv = random_bytes(12);
    $encrypted = openssl_encrypt($plainSecret, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($encrypted === false) {
        throw new \RuntimeException('Impossible de chiffrer le secret TOTP');
    }
    return base64_encode($iv . $tag . $encrypted);
}

function totpDecryptWithKey(string $stored, string $key): ?string
{
    $data = base64_decode($stored, true);
    if ($data === false || strlen($data) < 29) {
        return null;
    }

    $iv        = substr($data, 0, 12);
    $tag       = substr($data, 12, 16);
    $encrypted = substr($data, 28);
    $decrypted = openssl_decrypt($encrypted, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    return $decrypted === false ? null : $decrypted;
}

function totpEncryptSecret(string $plainSecret): string
{
    $keys = totpKeyCandidates();
    $primaryKey = reset($keys);
    return totpEncryptWithKey($plainSecret, $primaryKey);
}

function totpDecryptSecret(string $stored): string
{
    $result = totpDecryptSecretWithMeta($stored);
    if ($result === null) {
        throw new \RuntimeException('Impossible de déchiffrer le secret TOTP');
    }
    return $result['secret'];
}

function totpDecryptSecretWithMeta(string $stored): ?array
{
    foreach (totpKeyCandidates() as $source => $key) {
        $decrypted = totpDecryptWithKey($stored, $key);
        if ($decrypted !== null) {
            return [
                'secret' => $decrypted,
                'source' => $source,
            ];
        }
    }

    return null;
}
