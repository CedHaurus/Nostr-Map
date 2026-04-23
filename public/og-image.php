<?php
/**
 * og-image.php — Génère une image OG 1200×630 style header Nostr Map.
 *
 * GET params :
 *   title  : surtitre (ex. "FAQ", "Se connecter") — vide = page d'accueil
 *   sub    : description courte
 *   avatar : URL avatar profil (mode profil)
 *   slug   : @slug du profil
 */

declare(strict_types=1);

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');

// ── Paramètres ──────────────────────────────────────────────────────────────
$pageTitle = trim(strip_tags($_GET['title']  ?? ''));
$sub       = trim(strip_tags($_GET['sub']    ?? ''));
$avatar    = trim($_GET['avatar'] ?? '');
$slug      = trim(strip_tags($_GET['slug']   ?? ''));
$isProfile = ($avatar !== '' || $slug !== '');

if (mb_strlen($pageTitle) > 44) $pageTitle = mb_substr($pageTitle, 0, 42) . '…';
if (mb_strlen($sub)       > 88) $sub       = mb_substr($sub,       0, 86) . '…';

// ── Canvas ──────────────────────────────────────────────────────────────────
$W = 1200; $H = 630;
$img = imagecreatetruecolor($W, $H);
imagealphablending($img, true);
imagesavealpha($img, true);

// ── Couleurs (palette du site) ───────────────────────────────────────────────
$bg       = imagecolorallocate($img,  11,   9,  24);  // #0b0918 — fond très sombre
$purple   = imagecolorallocate($img, 139,  92, 246);  // #8b5cf6
$purpleL  = imagecolorallocate($img, 167, 139, 250);  // #a78bfa
$purpleDk = imagecolorallocate($img,  76,  29, 149);  // #4c1d95
$white    = imagecolorallocate($img, 255, 255, 255);
$grayText = imagecolorallocate($img, 190, 182, 215);  // texte secondaire
$dimText  = imagecolorallocate($img, 110, 100, 145);  // texte tertiaire

// ── Fond uni ────────────────────────────────────────────────────────────────
imagefilledrectangle($img, 0, 0, $W, $H, $bg);

// ── Halo violet en haut à gauche (comme le site) ────────────────────────────
for ($r = 350; $r > 10; $r -= 5) {
    $a = (int)(125 - ($r / 350) * 118);
    $c = imagecolorallocatealpha($img, 109, 40, 217, $a);
    imagefilledellipse($img, 0, 0, $r * 2, $r * 2, $c);
}

// ── Halo violet discret en bas à droite ─────────────────────────────────────
for ($r = 260; $r > 10; $r -= 5) {
    $a = (int)(125 - ($r / 260) * 118);
    $c = imagecolorallocatealpha($img, 91, 33, 182, $a);
    imagefilledellipse($img, $W, $H, $r * 2, $r * 2, $c);
}

// ── Grille de points subtile ─────────────────────────────────────────────────
$dotC = imagecolorallocatealpha($img, 139, 92, 246, 110);
for ($x = 70; $x < $W; $x += 70) {
    for ($y = 70; $y < $H; $y += 70) {
        imagefilledellipse($img, $x, $y, 2, 2, $dotC);
    }
}

// ── Ligne de séparation en bas (comme le header du site) ────────────────────
// Dégradé horizontal violet
for ($x = 0; $x < $W; $x++) {
    $t = $x / $W;
    $r2 = (int)(139 + $t * 28);
    $g2 = (int)(92  + $t * 47);
    $b2 = (int)(246 + $t * 4);
    $lc = imagecolorallocate($img, min(255,$r2), min(255,$g2), min(255,$b2));
    imageline($img, $x, $H - 4, $x, $H - 1, $lc);
}

// ── Police ───────────────────────────────────────────────────────────────────
$fontDir  = '/var/www/html/assets/fonts/';
$fontB    = $fontDir . 'Inter-Bold.ttf';
$fontR    = $fontDir . 'Inter-Regular.ttf';
$hasTTF   = file_exists($fontB) && file_exists($fontR);

// ── Dessin de l'étoile ✦ (4 branches) ───────────────────────────────────────
function drawStar(GdImage $img, int $cx, int $cy, int $size, int $color): void {
    $half = $size / 2;
    $thin = $size / 9;
    // 4 losanges (branches)
    $branches = [
        [[$cx, $cy - $half], [$cx + $thin, $cy], [$cx, $cy + $half], [$cx - $thin, $cy]], // vertical
        [[$cx - $half, $cy], [$cx, $cy - $thin], [$cx + $half, $cy], [$cx, $cy + $thin]], // horizontal
    ];
    foreach ($branches as $pts) {
        imagefilledpolygon($img, array_merge(...array_map(fn($p) => $p, $pts)), $color);
    }
}

// ── Layout ───────────────────────────────────────────────────────────────────
if ($isProfile) {
    // ── MODE PROFIL : avatar centré, grand, aucun texte ───────────────────────
    $avSize = 380;                            // grand cercle centré
    $cx     = (int)($W / 2);
    $cy     = (int)($H / 2);
    $avX    = $cx - (int)($avSize / 2);
    $avY    = $cy - (int)($avSize / 2);

    $avatarSafe = false;
    if ($avatar && filter_var($avatar, FILTER_VALIDATE_URL)) {
        // SSRF protection : HTTPS only, résolution DNS, blocage IP privées
        $avScheme = parse_url($avatar, PHP_URL_SCHEME);
        $avHost   = parse_url($avatar, PHP_URL_HOST);
        if ($avScheme === 'https' && $avHost) {
            $avIps = @gethostbynamel($avHost);
            if ($avIps) {
                $avatarSafe = true;
                foreach ($avIps as $avIp) {
                    if (!filter_var($avIp, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        $avatarSafe = false;
                        break;
                    }
                }
            }
        }
    }
    if ($avatarSafe) {
        $ctx    = stream_context_create([
            'http' => ['timeout' => 3, 'ignore_errors' => true],
            'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $avData = @file_get_contents($avatar, false, $ctx, 0, 2 * 1024 * 1024);
        if ($avData) {
            $avSrc = @imagecreatefromstring($avData);
            if ($avSrc) {
                // Halo extérieur doux (lueur violette autour du cercle)
                for ($r = $avSize / 2 + 40; $r > $avSize / 2 + 2; $r -= 2) {
                    $alpha = (int)(110 - ($r - $avSize / 2 - 2) / 38 * 108);
                    $gc    = imagecolorallocatealpha($img, 139, 92, 246, $alpha);
                    imagefilledellipse($img, $cx, $cy, $r * 2, $r * 2, $gc);
                }
                // Bordure violette pleine
                imagefilledellipse($img, $cx, $cy, $avSize + 10, $avSize + 10, $purple);

                // Redimensionner l'avatar
                $avDst = imagecreatetruecolor($avSize, $avSize);
                imagealphablending($avDst, false);
                imagesavealpha($avDst, true);
                $trans = imagecolorallocatealpha($avDst, 0, 0, 0, 127);
                imagefill($avDst, 0, 0, $trans);
                $sw = imagesx($avSrc); $sh = imagesy($avSrc);
                imagecopyresampled($avDst, $avSrc, 0, 0, 0, 0, $avSize, $avSize, $sw, $sh);

                // Masque circulaire
                $r2 = $avSize / 2;
                for ($px = 0; $px < $avSize; $px++) {
                    for ($py = 0; $py < $avSize; $py++) {
                        $dx = $px - $r2; $dy = $py - $r2;
                        if (sqrt($dx * $dx + $dy * $dy) > $r2) {
                            imagesetpixel($avDst, $px, $py, $trans);
                        }
                    }
                }

                imagecopy($img, $avDst, $avX, $avY, 0, 0, $avSize, $avSize);
                imagedestroy($avSrc);
                imagedestroy($avDst);
            }
        }
    } else {
        // Pas d'avatar — cercle placeholder avec initiale
        imagefilledellipse($img, $cx, $cy, $avSize + 10, $avSize + 10, $purple);
        imagefilledellipse($img, $cx, $cy, $avSize,      $avSize,      $purpleDk);
    }

} else {
    // ── MODE PAGE ─────────────────────────────────────────────────────────────
    // Zone centrale
    $centerY = (int)($H * 0.44);

    if ($hasTTF) {
        // Logo "Nostr Map" + étoile
        $logoSize  = $pageTitle ? 58 : 80;
        $logoText  = 'Nostr Map';
        $logoBox   = imagettfbbox($logoSize, 0, $fontB, $logoText);
        $logoW     = abs($logoBox[2] - $logoBox[0]);
        $starSize  = (int)($logoSize * 0.55);
        $gap       = 18;
        $totalW    = $logoW + $gap + $starSize;
        $startX    = (int)(($W - $totalW) / 2);
        $logoY     = $centerY;

        // Texte logo
        imagettftext($img, $logoSize, 0, $startX, $logoY, $white, $fontB, $logoText);

        // Étoile ✦ dessinée manuellement à côté
        $starCX = $startX + $logoW + $gap + (int)($starSize / 2);
        $starCY = $logoY  - (int)($logoSize * 0.38);
        drawStar($img, $starCX, $starCY, $starSize, $purple);

        // Tagline (toujours présente)
        $tagline  = 'Annuaire Nostr Francophone';
        $tagBox   = imagettfbbox(24, 0, $fontR, $tagline);
        $tagW     = abs($tagBox[2] - $tagBox[0]);
        $tagX     = (int)(($W - $tagW) / 2);
        imagettftext($img, 24, 0, $tagX, $logoY + 38, $grayText, $fontR, $tagline);

        // Sous-titre de page (si fourni)
        if ($pageTitle) {
            // Séparateur
            $sepY = $logoY + 90;
            $sepW = 60;
            $sepX = (int)(($W - $sepW) / 2);
            imagefilledrectangle($img, $sepX, $sepY, $sepX + $sepW, $sepY + 2, $purpleL);

            // Titre de la page sous le logo
            $ptBox = imagettfbbox(28, 0, $fontR, $pageTitle);
            $ptW   = abs($ptBox[2] - $ptBox[0]);
            $ptX   = (int)(($W - $ptW) / 2);
            imagettftext($img, 28, 0, $ptX, $sepY + 46, $purpleL, $fontR, $pageTitle);
        }

    } else {
        // Fallback GD
        $lw = strlen('Nostr Map') * imagefontwidth(5);
        imagestring($img, 5, ($W - $lw)/2, $centerY - 10, 'Nostr Map', $white);
        $tw2 = strlen('Annuaire Nostr Francophone') * imagefontwidth(4);
        imagestring($img, 4, ($W - $tw2)/2, $centerY + 25, 'Annuaire Nostr Francophone', $grayText);
        if ($pageTitle) {
            $pw = strlen($pageTitle) * imagefontwidth(4);
            imagestring($img, 4, ($W - $pw)/2, $centerY + 65, $pageTitle, $purpleL);
        }
    }
}

// ── Badge "nostrmap.fr" en bas à gauche (pages uniquement, pas les profils) ──
if (!$isProfile) {
    if ($hasTTF) {
        imagettftext($img, 17, 0, 54, $H - 32, $purpleL, $fontB, 'Nostr Map');
        drawStar($img, 54 + 94, $H - 44, 14, $purple);
        imagettftext($img, 14, 0, 54, $H - 16, $dimText, $fontR, 'nostrmap.fr');
    } else {
        imagestring($img, 3, 54, $H - 45, 'Nostr Map * nostrmap.fr', $purpleL);
    }
}

// ── Rendu ────────────────────────────────────────────────────────────────────
imagepng($img, null, 8);
imagedestroy($img);

// ── Helper ───────────────────────────────────────────────────────────────────
function wordWrapUTF8(string $text, int $maxChars): array {
    $words = explode(' ', $text);
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $test = $cur ? $cur . ' ' . $w : $w;
        if (mb_strlen($test) <= $maxChars) { $cur = $test; }
        else { if ($cur) $lines[] = $cur; $cur = $w; }
    }
    if ($cur) $lines[] = $cur;
    return array_slice($lines, 0, 3);
}
