<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';

// Déjà connecté ?
if (isAdminLoggedIn()) {
    redirect('/admin/');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = adminLogin(
        trim($_POST['username'] ?? ''),
        $_POST['password'] ?? ''
    );

    if ($result['success']) {
        $next = $_GET['next'] ?? '/admin/';
        if (!str_starts_with($next, '/admin')) $next = '/admin/';

        if (($result['step'] ?? '') === 'totp') {
            redirect('/admin/login-totp.php?next=' . urlencode($next));
        } else {
            redirect('/admin/totp-setup.php?next=' . urlencode($next));
        }
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion — Admin Nostr Map</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/admin.css">
<meta name="robots" content="noindex, nofollow">
</head>
<body class="login-page">

<div class="login-box">
    <div class="login-logo">
        <div class="login-logo-text">Nostr Map ✦</div>
        <div class="login-subtitle">Panneau d'administration</div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger">🔒 <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <div class="form-group">
            <label class="form-label">Identifiant</label>
            <input
                type="text"
                name="username"
                class="form-control"
                value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                placeholder="Votre username"
                autofocus
                required
                autocomplete="username"
            >
        </div>
        <div class="form-group">
            <label class="form-label">Mot de passe</label>
            <input
                type="password"
                name="password"
                class="form-control"
                placeholder="••••••••••••"
                required
                autocomplete="current-password"
            >
        </div>
        <button type="submit" class="btn btn-primary w-full" style="justify-content:center;margin-top:.25rem;">
            Se connecter
        </button>
    </form>

    <p style="margin-top:1.5rem;text-align:center;font-size:.75rem;color:var(--text-muted);">
        Accès réservé à l'équipe de modération Nostr Map.
    </p>
</div>

</body>
</html>
