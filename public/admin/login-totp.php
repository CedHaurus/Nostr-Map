<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

// Déjà connecté ?
if (isAdminLoggedIn()) redirect('/admin/');

// Pas de session pending_totp → retour login
$pending = pendingUser('totp');
if (!$pending) {
    flash('Session expirée. Reconnectez-vous.', 'danger');
    redirect('/admin/login.php');
}

$error = '';
$next  = $_GET['next'] ?? '/admin/';
if (!str_starts_with($next, '/admin')) $next = '/admin/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting TOTP
    $_SESSION['totp_attempts'] = ($_SESSION['totp_attempts'] ?? 0) + 1;
    if ($_SESSION['totp_attempts'] > 5) {
        unset($_SESSION['pending_totp'], $_SESSION['totp_attempts']);
        flash('Trop de tentatives. Recommencez depuis le début.', 'danger');
        redirect('/admin/login.php');
    }

    $code = trim($_POST['totp_code'] ?? '');
    $db   = getDB();

    $stmt = $db->prepare('SELECT totp_secret FROM admin_users WHERE id = ? AND totp_enabled = 1');
    $stmt->execute([$pending['user_id']]);
    $row = $stmt->fetch();

    $secretMeta = null;
    if ($row && $row['totp_secret']) {
        $secretMeta = totpDecryptSecretWithMeta($row['totp_secret']);
        if ($secretMeta === null && preg_match('/^[A-Z2-7]{32,}$/', (string)$row['totp_secret'])) {
            $secretMeta = [
                'secret' => $row['totp_secret'],
                'source' => 'plain',
            ];
        }
    }

    if (!$secretMeta || !totpVerify($secretMeta['secret'], $code)) {
        $error = 'Code incorrect. Vérifiez votre application d\'authentification.';
    } else {
        if ($secretMeta['source'] !== 'totp' && totpHasDedicatedKey()) {
            $db->prepare('UPDATE admin_users SET totp_secret = ? WHERE id = ?')
               ->execute([totpEncryptSecret($secretMeta['secret']), $pending['user_id']]);
        }
        completeTotpLogin();
        redirect($next);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vérification 2FA — Admin Nostr Map</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/admin.css">
<meta name="robots" content="noindex, nofollow">
</head>
<body class="login-page">

<div class="login-box">
    <div class="login-logo">
        <div class="login-logo-text">Nostr Map ✦</div>
        <div class="login-subtitle">Vérification à deux facteurs</div>
    </div>

    <div style="text-align:center;margin-bottom:1.5rem;">
        <div style="font-size:2rem;margin-bottom:.5rem;">🔐</div>
        <p style="color:var(--text-muted);font-size:.875rem;line-height:1.6;">
            Bonjour <strong><?= h($pending['username']) ?></strong>, entrez le code à 6 chiffres
            affiché dans votre application d'authentification.
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= h($error) ?>
            <?php $remaining = 5 - ($_SESSION['totp_attempts'] ?? 0); ?>
            <?php if ($remaining <= 3): ?>
                <div style="margin-top:.3rem;font-size:.8rem;">
                    <?= $remaining ?> tentative<?= $remaining > 1 ? 's' : '' ?> restante<?= $remaining > 1 ? 's' : '' ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off" id="totp-form">
        <div class="form-group">
            <label class="form-label" style="text-align:center;display:block;">Code de vérification</label>
            <input
                type="text"
                name="totp_code"
                id="totp-input"
                class="form-control"
                inputmode="numeric"
                pattern="[0-9 ]{6,7}"
                maxlength="7"
                placeholder="000 000"
                autofocus
                required
                autocomplete="one-time-code"
                style="text-align:center;font-size:1.6rem;font-weight:700;letter-spacing:.2em;font-family:monospace;"
            >
        </div>
        <button type="submit" class="btn btn-primary w-full" style="justify-content:center;margin-top:.25rem;">
            Vérifier
        </button>
    </form>

    <div style="margin-top:1.25rem;text-align:center;">
        <a href="/admin/login.php" style="font-size:.8rem;color:var(--text-muted);">← Retour à la connexion</a>
    </div>
</div>

<script>
// Auto-submit quand 6 chiffres sont saisis
const input = document.getElementById('totp-input');
input.addEventListener('input', function() {
    const digits = this.value.replace(/\D/g, '');
    if (digits.length === 6) {
        this.value = digits;
        document.getElementById('totp-form').submit();
    }
});
// Formater avec espace après 3 chiffres
input.addEventListener('keyup', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 3) v = v.slice(0,3) + ' ' + v.slice(3,6);
    this.value = v;
});
</script>

</body>
</html>
