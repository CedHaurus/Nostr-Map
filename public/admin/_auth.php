<?php
/**
 * _auth.php — Système d'authentification admin
 * Sessions PHP sécurisées, CSRF, rate-limiting, rôles admin/modo.
 */

declare(strict_types=1);

require_once '/var/www/config/db.php';
require_once __DIR__ . '/_totp.php';

// ─── Configuration session sécurisée ─────────────────────────────────────────

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', 172800); // 48h — évite le GC PHP (défaut 24 min)
    session_set_cookie_params([
        'lifetime' => 172800, // 48h
        'path'     => '/admin',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

define('ADMIN_SESSION_KEY', 'nm_admin');
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_SECONDS',    900);   // 15 min
define('ADMIN_SESSION_SECONDS', 172800); // 48h

function maintenanceBypassToken(): string
{
    return substr(hash_hmac('sha256', 'maintenance-bypass', getenv('JWT_SECRET') ?: 'nostrmap-salt'), 0, 32);
}

function maintenanceBypassCookieOptions(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function setMaintenanceBypassCookie(): void
{
    if (headers_sent()) return;

    $token = maintenanceBypassToken();
    setcookie('nm_preview', $token, maintenanceBypassCookieOptions(time() + ADMIN_SESSION_SECONDS));
    $_COOKIE['nm_preview'] = $token;
}

function clearMaintenanceBypassCookie(): void
{
    if (headers_sent()) return;

    setcookie('nm_preview', '', maintenanceBypassCookieOptions(time() - 3600));
    unset($_COOKIE['nm_preview']);
}

function sendLogoutBrowserResetHeaders(): void
{
    if (headers_sent()) return;

    // Evite qu'une page publique ouverte avec le bypass preview reste affichée
    // depuis le cache navigateur juste après la déconnexion admin.
    header('Clear-Site-Data: "cache"', false);
    header('Cache-Control: no-store, no-cache, must-revalidate', true);
    header('Pragma: no-cache', true);
}

// ─── Authentification ─────────────────────────────────────────────────────────

function adminLogin(string $username, string $password): array {
    $db = getDB();

    // Rate limiting par IP (stocké en DB, pas en session)
    $ip = getClientIp();

    $rlStmt = $db->prepare(
        'SELECT attempts, first_attempt FROM login_attempts WHERE ip = ?'
    );
    $rlStmt->execute([$ip]);
    $rl = $rlStmt->fetch();

    if ($rl) {
        $elapsed = time() - strtotime($rl['first_attempt']);
        // Reset fenêtre après lockout
        if ($rl['attempts'] >= MAX_LOGIN_ATTEMPTS && $elapsed > LOCKOUT_SECONDS) {
            $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
            $rl = null;
        }
    }

    if ($rl && $rl['attempts'] >= MAX_LOGIN_ATTEMPTS) {
        $remaining = LOCKOUT_SECONDS - (time() - strtotime($rl['first_attempt']));
        return ['success' => false,
                'error' => "Trop de tentatives. Réessayez dans " . ceil($remaining/60) . " min."];
    }

    // Chercher l'utilisateur
    $stmt = $db->prepare(
        'SELECT id, username, password, role, active, totp_secret, totp_enabled
         FROM admin_users WHERE username = ? LIMIT 1'
    );
    $stmt->execute([trim($username)]);
    $user = $stmt->fetch();

    if (!$user || !$user['active'] || !password_verify($password, $user['password'])) {
        $db->prepare(
            'INSERT INTO login_attempts (ip, attempts, first_attempt, last_attempt)
             VALUES (?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE attempts = attempts + 1, last_attempt = NOW()'
        )->execute([$ip]);
        return ['success' => false, 'error' => 'Identifiants incorrects.'];
    }

    // Réinitialiser les tentatives après succès
    $db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);

    // Prévention fixation de session
    session_regenerate_id(true);

    // Données de session temporaire (avant validation TOTP)
    $pending = [
        'user_id'  => $user['id'],
        'username' => $user['username'],
        'role'     => $user['role'],
        'ip'       => $ip,
        'ua'       => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200),
        'ts'       => time(),
    ];

    if ($user['totp_enabled'] && $user['totp_secret']) {
        // TOTP déjà configuré → étape vérification
        $_SESSION['pending_totp']  = $pending;
        $_SESSION['totp_attempts'] = 0;
        return ['success' => true, 'step' => 'totp'];
    } else {
        // TOTP non configuré → étape setup obligatoire
        $_SESSION['pending_setup'] = $pending;
        return ['success' => true, 'step' => 'setup'];
    }
}

/**
 * Finalise la connexion après validation TOTP (ou setup).
 * Appelé depuis login-totp.php ou totp-setup.php.
 */
function completeTotpLogin(): void
{
    $pending = $_SESSION['pending_totp'] ?? $_SESSION['pending_setup'] ?? null;
    if (!$pending) return;

    unset($_SESSION['pending_totp'], $_SESSION['pending_setup'], $_SESSION['totp_attempts']);

    // Cookie de bypass maintenance (path=/ pour être envoyé sur toutes les pages)
    setMaintenanceBypassCookie();

    $_SESSION[ADMIN_SESSION_KEY] = [
        'id'       => $pending['user_id'],
        'username' => $pending['username'],
        'role'     => $pending['role'],
        'ip'       => $pending['ip'],
        'ua'       => $pending['ua'],
        'login_at' => time(),
    ];

    $db = getDB();
    $db->prepare('UPDATE admin_users SET last_login = NOW() WHERE id = ?')
       ->execute([$pending['user_id']]);

    logActivity('login', 'session', null, 'Connexion réussie (TOTP)');
}

/**
 * Retourne les données pending (totp ou setup) si elles existent et ne sont pas expirées (5 min).
 */
function pendingUser(string $type = 'any'): ?array
{
    if ($type === 'any') {
        $p = $_SESSION['pending_totp'] ?? $_SESSION['pending_setup'] ?? null;
    } else {
        $p = $_SESSION["pending_{$type}"] ?? null;
    }
    if (!$p) return null;
    if ((time() - ($p['ts'] ?? 0)) > 300) {
        unset($_SESSION['pending_totp'], $_SESSION['pending_setup']);
        return null;
    }
    return $p;
}

function adminLogout(): void {
    if (isAdminLoggedIn()) {
        logActivity('logout', 'session', null, 'Déconnexion');
    }
    $_SESSION = [];
    session_destroy();
    // Supprimer le cookie de bypass maintenance
    clearMaintenanceBypassCookie();
    sendLogoutBrowserResetHeaders();
    header('Location: /admin/login.php');
    exit;
}

// ─── Vérification session ─────────────────────────────────────────────────────

function isAdminLoggedIn(): bool {
    if (empty($_SESSION[ADMIN_SESSION_KEY])) return false;
    $s = $_SESSION[ADMIN_SESSION_KEY];

    // L'IP change trop facilement sur mobile, avec Cloudflare, IPv4/IPv6,
    // ou lors d'un changement de réseau. On garde une vérification sur l'UA,
    // et on tolère les changements d'IP pendant la durée de vie de la session.
    $currentUa = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 200);
    if (($s['ua'] ?? '') !== $currentUa) {
        session_destroy();
        clearMaintenanceBypassCookie();
        return false;
    }

    // Timeout session : 8h
    if ((time() - $s['login_at']) > ADMIN_SESSION_SECONDS) {
        session_destroy();
        clearMaintenanceBypassCookie();
        return false;
    }

    // Auto-répare le bypass si le navigateur a perdu le cookie preview
    // alors que la session admin est encore valide.
    setMaintenanceBypassCookie();

    // Garder l'IP courante à titre informatif pour les logs/diagnostics.
    $_SESSION[ADMIN_SESSION_KEY]['ip'] = getClientIp();

    return true;
}

function requireAdmin(string $minRole = 'modo'): array {
    // Rediriger vers les étapes TOTP si une session pending existe
    $currentFile = basename($_SERVER['PHP_SELF'] ?? '');
    if (!in_array($currentFile, ['login-totp.php', 'totp-setup.php', 'login.php', 'logout.php'], true)) {
        if (!empty($_SESSION['pending_totp'])) {
            header('Location: /admin/login-totp.php');
            exit;
        }
        if (!empty($_SESSION['pending_setup'])) {
            header('Location: /admin/totp-setup.php');
            exit;
        }
    }

    if (!isAdminLoggedIn()) {
        header('Location: /admin/login.php?next=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
    $s = $_SESSION[ADMIN_SESSION_KEY];
    if ($minRole === 'admin' && $s['role'] !== 'admin') {
        adminDie('Accès refusé. Privilèges administrateur requis.');
    }
    return $s;
}

function isRole(string $role): bool {
    return ($_SESSION[ADMIN_SESSION_KEY]['role'] ?? '') === $role;
}

function currentAdmin(): array {
    return $_SESSION[ADMIN_SESSION_KEY] ?? [];
}

// ─── CSRF ─────────────────────────────────────────────────────────────────────

function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function verifyCsrf(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals(csrfToken(), $token)) {
        adminDie('Jeton CSRF invalide. Rechargez la page et réessayez.');
    }
}

// ─── Génération de slug (admin, sans charger _helpers.php) ───────────────────

function generateAdminSlug(string $npub, ?string $nip05, PDO $db): string {
    $check = static function(string $slug) use ($db): bool {
        $s = $db->prepare('SELECT 1 FROM profiles WHERE slug = ?');
        $s->execute([$slug]);
        return !$s->fetchColumn();
    };

    if ($nip05 && preg_match('/^([a-z0-9_.-]+)@/i', $nip05, $m)) {
        $base = strtolower(preg_replace('/[^a-z0-9_-]/', '', $m[1]));
        if (strlen($base) >= 3) {
            $slug = substr($base, 0, 30);
            if ($check($slug)) return $slug;
            for ($i = 2; $i <= 99; $i++) {
                if ($check($slug . $i)) return $slug . $i;
            }
        }
    }
    $base = strtolower(preg_replace('/[^a-z0-9]/', '', substr($npub, 5, 12)));
    if (strlen($base) < 6) $base = bin2hex(random_bytes(4));
    $slug = substr($base, 0, 12);
    if ($check($slug)) return $slug;
    for ($i = 2; $i <= 999; $i++) {
        if ($check($slug . $i)) return $slug . $i;
    }
    return substr($base, 0, 8) . bin2hex(random_bytes(2));
}

// ─── Validation npub ─────────────────────────────────────────────────────────

function isValidNpub(string $npub): bool {
    return strlen($npub) >= 60
        && str_starts_with($npub, 'npub1')
        && (bool)preg_match('/^npub1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]+$/i', $npub);
}

// ─── Validation mot de passe ─────────────────────────────────────────────────

/**
 * Valide un mot de passe admin/modo.
 * Retourne null si valide, ou un message d'erreur.
 */
function validatePassword(string $password): ?string {
    if (strlen($password) < 16) {
        return 'Mot de passe : 16 caractères minimum.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Mot de passe : au moins une lettre majuscule requise.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Mot de passe : au moins une lettre minuscule requise.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Mot de passe : au moins un chiffre requis.';
    }
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        return 'Mot de passe : au moins un caractère spécial requis (ex: !@#$%^&*).';
    }
    return null;
}

// ─── Log d'activité ───────────────────────────────────────────────────────────

function logActivity(string $action, ?string $targetType, ?string $targetId, ?string $details = null): void {
    try {
        $admin = currentAdmin();
        if (empty($admin['id'])) return;
        $db = getDB();
        $db->prepare(
            'INSERT INTO admin_activity (admin_id, action, target_type, target_id, details)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$admin['id'], $action, $targetType, $targetId, $details]);
    } catch (Throwable) { /* silencieux */ }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function getClientIp(): string {
    foreach (['HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        if (!empty($_SERVER[$key])) return $_SERVER[$key];
    }
    return '0.0.0.0';
}

function adminDie(string $msg): void {
    http_response_code(403);
    echo adminLayout('Erreur', '<div class="alert alert-danger"><strong>⛔ ' . htmlspecialchars($msg) . '</strong></div>');
    exit;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $type = 'success'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function getFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ─── Layout principal ─────────────────────────────────────────────────────────

function adminLayout(string $title, string $content, bool $withNav = true): string {
    $admin   = currentAdmin();
    $flash   = getFlash();
    $flashHtml = '';
    if ($flash) {
        $flashHtml = '<div class="alert alert-' . h($flash['type']) . '">' . h($flash['msg']) . '</div>';
    }

    $nav = '';
    if ($withNav && isAdminLoggedIn()) {
        $isAdmin = isRole('admin');

        // Compteur suppressions en attente (admin seulement)
        $pendingDel      = 0;
        $pendingReports  = 0;
        $newContacts     = 0;
        if ($isAdmin) {
            try {
                $pdStmt = getDB()->query('SELECT COUNT(*) FROM profiles WHERE status = "pending_deletion"');
                $pendingDel = (int)$pdStmt->fetchColumn();
            } catch (Throwable) {}
        }
        try {
            $prStmt = getDB()->query('SELECT COUNT(*) FROM reports WHERE status = "pending"');
            $pendingReports = (int)$prStmt->fetchColumn();
        } catch (Throwable) {}
        try {
            $ctStmt = getDB()->query('SELECT COUNT(*) FROM contact_messages WHERE status = "new"');
            $newContacts = (int)$ctStmt->fetchColumn();
        } catch (Throwable) {}

        $delBadge = $pendingDel > 0
            ? ' <span style="background:var(--danger);color:#fff;border-radius:10px;padding:.1rem .45rem;font-size:.7rem;font-weight:700;margin-left:.25rem;">' . $pendingDel . '</span>'
            : '';
        $reportBadge = $pendingReports > 0
            ? ' <span style="background:var(--danger);color:#fff;border-radius:10px;padding:.1rem .45rem;font-size:.7rem;font-weight:700;margin-left:.25rem;">' . $pendingReports . '</span>'
            : '';
        $contactBadge = $newContacts > 0
            ? ' <span style="background:var(--danger);color:#fff;border-radius:10px;padding:.1rem .45rem;font-size:.7rem;font-weight:700;margin-left:.25rem;">' . $newContacts . '</span>'
            : '';

        $nav = '
        <nav class="sidebar">
            <div class="sidebar-logo">
                <a href="/admin/">Nostr Map ✦</a>
                <span class="sidebar-badge sidebar-badge-' . h($admin['role']) . '">' . h($admin['role']) . '</span>
            </div>
            <ul class="nav-list">
                <li><a href="/admin/" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : '') . '">
                    <span class="nav-icon">📊</span> Dashboard
                </a></li>
                <li><a href="/admin/profiles.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'profiles.php' ? 'active' : '') . '">
                    <span class="nav-icon">👥</span> Profils
                </a></li>
                <li><a href="/admin/profile-create.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'profile-create.php' ? 'active' : '') . '">
                    <span class="nav-icon">➕</span> Créer une fiche
                </a></li>
                <li><a href="/admin/proposals.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'proposals.php' ? 'active' : '') . '">
                    <span class="nav-icon">📬</span> Propositions
                </a></li>
                <li><a href="/admin/reports.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'active' : '') . '">
                    <span class="nav-icon">🚩</span> Signalements' . $reportBadge . '
                </a></li>
                <li><a href="/admin/contacts.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'contacts.php' ? 'active' : '') . '">
                    <span class="nav-icon">✉️</span> Messages contact' . $contactBadge . '
                </a></li>
                <li><a href="/admin/activity.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'activity.php' ? 'active' : '') . '">
                    <span class="nav-icon">📋</span> Activité équipe
                </a></li>
                <li><a href="/admin/user-activity.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'user-activity.php' ? 'active' : '') . '">
                    <span class="nav-icon">🗒️</span> Journal annuaire
                </a></li>
                ' . ($isAdmin ? '
                <li class="nav-separator">Administration</li>
                <li><a href="/admin/deletions.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'deletions.php' ? 'active' : '') . '">
                    <span class="nav-icon">🗑️</span> Suppressions' . $delBadge . '
                </a></li>
                <li><a href="/admin/users.php" class="nav-link ' . (basename($_SERVER['PHP_SELF']) === 'users.php' ? 'active' : '') . '">
                    <span class="nav-icon">🔑</span> Utilisateurs
                </a></li>' : '') . '
            </ul>
            <div class="sidebar-footer">
                ' . (function() use ($isAdmin) {
                    $maintActive = file_exists('/var/www/html/storage/.maintenance');
                    if ($isAdmin) {
                        // Widget complet avec bouton toggle (admin uniquement)
                        return '
                <div class="maintenance-widget" id="maint-widget">
                    <div class="maint-status">
                        <span class="maint-dot" id="maint-dot"></span>
                        <span id="maint-label">Chargement…</span>
                    </div>
                    <button class="maint-toggle-btn" id="maint-btn" onclick="toggleMaintenance()">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                        <span id="maint-btn-label">…</span>
                    </button>
                </div>
                <script>
                (function() {
                    const flag = ' . json_encode($maintActive) . ';
                    function render(active) {
                        const dot   = document.getElementById("maint-dot");
                        const label = document.getElementById("maint-label");
                        const btn   = document.getElementById("maint-btn");
                        const bl    = document.getElementById("maint-btn-label");
                        if (active) {
                            dot.style.background   = "#ef4444";
                            dot.style.boxShadow    = "0 0 6px #ef4444";
                            label.textContent      = "Maintenance ON";
                            label.style.color      = "#ef4444";
                            btn.style.background   = "rgba(239,68,68,.15)";
                            btn.style.borderColor  = "rgba(239,68,68,.4)";
                            btn.style.color        = "#ef4444";
                            bl.textContent         = "Désactiver";
                        } else {
                            dot.style.background   = "#10b981";
                            dot.style.boxShadow    = "0 0 6px #10b981";
                            label.textContent      = "Site en ligne";
                            label.style.color      = "#10b981";
                            btn.style.background   = "rgba(16,185,129,.1)";
                            btn.style.borderColor  = "rgba(16,185,129,.3)";
                            btn.style.color        = "#10b981";
                            bl.textContent         = "Maintenance";
                        }
                    }
                    window._maintActive = flag;
                    render(flag);
                    window.toggleMaintenance = function() {
                        const btn = document.getElementById("maint-btn");
                        btn.disabled = true;
                        fetch("/admin/maintenance-toggle.php", {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: "csrf_token=" + encodeURIComponent(' . json_encode(csrfToken()) . ')
                        })
                        .then(r => r.json())
                        .then(d => { window._maintActive = d.active; render(d.active); })
                        .catch(() => {
                            const label = document.getElementById("maint-label");
                            if (label) {
                                label.textContent = "Erreur maintenance";
                                label.style.color = "#f59e0b";
                            }
                        })
                        .finally(() => { btn.disabled = false; });
                    };
                })();
                </script>';
                    } elseif ($maintActive) {
                        // Indicateur lecture seule pour les modos (uniquement si maintenance active)
                        return '
                <div class="maintenance-widget" style="pointer-events:none;">
                    <div class="maint-status">
                        <span class="maint-dot" style="background:#ef4444;box-shadow:0 0 6px #ef4444;"></span>
                        <span style="color:#ef4444;font-size:.75rem;font-weight:600;">Maintenance active</span>
                    </div>
                    <div style="font-size:.7rem;color:#64748b;margin-top:.3rem;padding-left:1.1rem;">
                        Vous naviguez en mode bypass.<br>Le site public est hors ligne.
                    </div>
                </div>';
                    }
                    return '';
                })() . '
                <div class="sidebar-user">
                    <span>👤 ' . h($admin['username']) . '</span>
                    <a href="/admin/logout.php" class="btn-logout" title="Déconnexion">⏻</a>
                </div>
                <a href="/" target="_blank" class="sidebar-frontlink">↗ Voir le site</a>
            </div>
        </nav>';
    }

    $hasSidebar = $withNav && isAdminLoggedIn();

    $mobileTopbar = '';
    $backdrop     = '';
    $sidebarJs    = '';
    if ($hasSidebar) {
        $mobileTopbar = '
<div class="mobile-topbar">
  <button class="sidebar-toggle" id="sidebar-toggle" aria-label="Menu" onclick="toggleSidebar()">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
      <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
    </svg>
  </button>
  <span class="mobile-topbar-title">Nostr Map ✦ Admin</span>
  <div class="mobile-topbar-actions">
    <a href="/admin/logout.php" class="btn-logout" title="Déconnexion" style="font-size:1.2rem;">⏻</a>
  </div>
</div>';
        $backdrop = '<div class="sidebar-backdrop" id="sidebar-backdrop" onclick="closeSidebar()"></div>';
        $sidebarJs = '
<script>
function toggleSidebar() {
  const s = document.querySelector(".sidebar");
  const b = document.getElementById("sidebar-backdrop");
  const open = s.classList.toggle("open");
  b.classList.toggle("visible", open);
  document.body.style.overflow = open ? "hidden" : "";
}
function closeSidebar() {
  document.querySelector(".sidebar")?.classList.remove("open");
  document.getElementById("sidebar-backdrop")?.classList.remove("visible");
  document.body.style.overflow = "";
}
// Fermer sur navigation (clic sur un lien de la sidebar)
document.querySelectorAll(".nav-link").forEach(a => a.addEventListener("click", closeSidebar));
</script>';
    }

    return '<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . h($title) . ' — Admin Nostr Map</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/admin/assets/admin.css">
<meta name="robots" content="noindex, nofollow">
</head>
<body class="' . ($hasSidebar ? 'has-sidebar' : 'login-page') . '">
' . $backdrop . '
' . $nav . '
' . $mobileTopbar . '
<main class="main-content">
    <div class="page-header">
        <h1 class="page-title">' . h($title) . '</h1>
    </div>
    ' . $flashHtml . '
    ' . $content . '
</main>
' . $sidebarJs . '
</body></html>';
}
