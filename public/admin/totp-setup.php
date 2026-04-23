<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

// Déjà connecté ?
if (isAdminLoggedIn()) redirect('/admin/');

// Pas de session pending_setup → retour login
$pending = pendingUser('setup');
if (!$pending) {
    flash('Session expirée. Reconnectez-vous.', 'danger');
    redirect('/admin/login.php');
}

$next  = $_GET['next'] ?? '/admin/';
if (!str_starts_with($next, '/admin')) $next = '/admin/';

$error   = '';
$success = false;

// Générer (ou récupérer) le secret temporaire pour cet utilisateur
if (empty($_SESSION['totp_setup_secret'])) {
    $_SESSION['totp_setup_secret'] = totpGenerateSecret();
}
$secret = $_SESSION['totp_setup_secret'];
$uri    = totpUri($secret, $pending['username']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['totp_code'] ?? '');

    if (!totpVerify($secret, $code)) {
        $error = 'Code incorrect. Scannez bien le QR code et réessayez.';
    } else {
        // Enregistrer le secret (chiffré) et activer TOTP
        $db = getDB();
        $db->prepare(
            'UPDATE admin_users SET totp_secret = ?, totp_enabled = 1 WHERE id = ?'
        )->execute([totpEncryptSecret($secret), $pending['user_id']]);

        unset($_SESSION['totp_setup_secret']);
        completeTotpLogin();

        logActivity('totp_setup', 'admin_user', (string)$pending['user_id'], 'TOTP configuré');
        redirect($next);
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Configuration 2FA — Admin Nostr Map</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/admin.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<meta name="robots" content="noindex, nofollow">
<style>
.setup-box {
    width: 100%;
    max-width: 480px;
    margin: 3rem auto;
    padding: 0 1rem;
}
.step {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.5rem;
}
.step-num {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--primary);
    color: #fff;
    font-weight: 700;
    font-size: .85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    margin-top: .1rem;
}
.step-body { flex: 1; }
.step-title { font-weight: 700; font-size: .95rem; margin-bottom: .35rem; }
.step-desc { color: var(--text-muted); font-size: .85rem; line-height: 1.6; }
.qr-wrap {
    background: #fff;
    border-radius: 10px;
    padding: 12px;
    display: inline-block;
    margin: .75rem 0;
}
.secret-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: .6rem .85rem;
    font-family: monospace;
    font-size: .85rem;
    letter-spacing: .1em;
    word-break: break-all;
    margin-top: .5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
}
</style>
</head>
<body style="background:var(--bg-dark,#0d0d1a);min-height:100vh;">

<div class="setup-box">
    <div style="text-align:center;margin-bottom:2rem;">
        <div style="font-size:2.5rem;margin-bottom:.5rem;">🔐</div>
        <h1 style="font-size:1.4rem;font-weight:800;">Configuration de la 2FA</h1>
        <p style="color:var(--text-muted);font-size:.875rem;margin-top:.4rem;">
            Bonjour <strong><?= h($pending['username']) ?></strong> — La double authentification est
            obligatoire pour accéder au panel.
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger" style="margin-bottom:1.25rem;"><?= h($error) ?></div>
    <?php endif; ?>

    <div class="panel" style="padding:1.5rem;">

        <!-- Étape 1 -->
        <div class="step">
            <div class="step-num">1</div>
            <div class="step-body">
                <div class="step-title">Installez une application d'authentification</div>
                <div class="step-desc">
                    Si vous n'en avez pas encore :
                    <strong>Google Authenticator</strong>, <strong>Aegis</strong> (Android),
                    <strong>Raivo OTP</strong> (iOS) ou tout autre client TOTP.
                </div>
            </div>
        </div>

        <!-- Étape 2 -->
        <div class="step">
            <div class="step-num">2</div>
            <div class="step-body">
                <div class="step-title">Scannez ce QR code</div>
                <div class="step-desc">Dans votre application, choisissez "Ajouter un compte" et scannez :</div>
                <div class="qr-wrap" id="qr-code"></div>
                <div class="step-desc" style="margin-top:.25rem;">
                    Ou saisissez la clé manuellement :
                </div>
                <div class="secret-box">
                    <span id="secret-text"><?= h(implode(' ', str_split($secret, 4))) ?></span>
                    <button type="button" onclick="copySecret()" class="btn btn-ghost btn-sm" id="copy-btn" style="flex-shrink:0;">
                        📋 Copier
                    </button>
                </div>
            </div>
        </div>

        <!-- Étape 3 : Vérification -->
        <div class="step">
            <div class="step-num">3</div>
            <div class="step-body">
                <div class="step-title">Confirmez le code</div>
                <div class="step-desc" style="margin-bottom:.75rem;">
                    Entrez le code à 6 chiffres affiché dans votre application pour confirmer la configuration.
                </div>
                <form method="POST" autocomplete="off" id="verify-form">
                    <div style="display:flex;gap:.6rem;align-items:center;">
                        <input
                            type="text"
                            name="totp_code"
                            id="totp-input"
                            class="form-control"
                            inputmode="numeric"
                            pattern="[0-9 ]{6,7}"
                            maxlength="7"
                            placeholder="000 000"
                            required
                            autocomplete="one-time-code"
                            style="text-align:center;font-size:1.4rem;font-weight:700;letter-spacing:.2em;font-family:monospace;max-width:160px;"
                        >
                        <button type="submit" class="btn btn-primary" style="flex-shrink:0;">
                            ✓ Activer la 2FA
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>

    <div style="text-align:center;margin-top:1rem;">
        <a href="/admin/login.php" style="font-size:.8rem;color:var(--text-muted);">← Annuler et retourner à la connexion</a>
    </div>
</div>

<script>
// Générer le QR code côté client
new QRCode(document.getElementById('qr-code'), {
    text:   <?= json_encode($uri) ?>,
    width:  180,
    height: 180,
    colorDark:  '#000000',
    colorLight: '#ffffff',
    correctLevel: QRCode.CorrectLevel.M
});

// Copier la clé secrète
function copySecret() {
    const raw = <?= json_encode($secret) ?>;
    navigator.clipboard.writeText(raw).then(() => {
        const btn = document.getElementById('copy-btn');
        btn.textContent = '✓ Copié';
        setTimeout(() => btn.textContent = '📋 Copier', 2000);
    });
}

// Auto-submit sur 6 chiffres
const input = document.getElementById('totp-input');
input.addEventListener('input', function() {
    const digits = this.value.replace(/\D/g, '');
    if (digits.length === 6) {
        this.value = digits;
        document.getElementById('verify-form').submit();
    }
});
input.addEventListener('keyup', function() {
    let v = this.value.replace(/\D/g, '');
    if (v.length > 3) v = v.slice(0,3) + ' ' + v.slice(3,6);
    this.value = v;
});
</script>

</body>
</html>
