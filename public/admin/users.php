<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin('admin'); // Admin seulement
$db    = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Créer un utilisateur ────────────────────────────────────────────────
    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $role     = $_POST['role'] ?? 'modo';
        $password = $_POST['password'] ?? '';

        $pwError = validatePassword($password);
        if (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
            flash('Username invalide (3-30 chars, lettres/chiffres/-/_).', 'danger');
        } elseif ($pwError) {
            flash($pwError, 'danger');
        } elseif (!in_array($role, ['admin','modo'])) {
            flash('Rôle invalide.', 'danger');
        } else {
            // Vérifier unicité
            $dup = $db->prepare('SELECT 1 FROM admin_users WHERE username=?');
            $dup->execute([$username]);
            if ($dup->fetchColumn()) {
                flash("L'identifiant \"{$username}\" est déjà utilisé.", 'danger');
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $db->prepare(
                    'INSERT INTO admin_users (username, password, role, created_by) VALUES (?,?,?,?)'
                )->execute([$username, $hash, $role, $admin['id']]);
                logActivity('create_admin_user', 'admin_user', $username, "Rôle: {$role}");
                flash("Utilisateur \"{$username}\" créé avec le rôle {$role}.", 'success');
            }
        }
        redirect('/admin/users.php');
    }

    // ── Changer le rôle ─────────────────────────────────────────────────────
    if ($action === 'set_role') {
        $userId  = (int)($_POST['user_id'] ?? 0);
        $newRole = $_POST['role'] ?? '';
        if ($userId === $admin['id']) { flash('Vous ne pouvez pas changer votre propre rôle.', 'danger'); redirect('/admin/users.php'); }
        if (!in_array($newRole, ['admin','modo'])) { flash('Rôle invalide.', 'danger'); redirect('/admin/users.php'); }
        $db->prepare('UPDATE admin_users SET role=? WHERE id=?')->execute([$newRole, $userId]);
        logActivity('set_role', 'admin_user', (string)$userId, "Nouveau rôle: {$newRole}");
        flash('Rôle mis à jour.', 'success');
        redirect('/admin/users.php');
    }

    // ── Reset mot de passe ──────────────────────────────────────────────────
    if ($action === 'reset_password') {
        $userId   = (int)($_POST['user_id'] ?? 0);
        $newPass  = $_POST['new_password'] ?? '';
        $pwError  = validatePassword($newPass);
        if ($pwError) { flash($pwError, 'danger'); redirect('/admin/users.php'); }
        $hash = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
        $db->prepare('UPDATE admin_users SET password=? WHERE id=?')->execute([$hash, $userId]);
        logActivity('reset_password', 'admin_user', (string)$userId);
        flash('Mot de passe réinitialisé.', 'success');
        redirect('/admin/users.php');
    }

    // ── Reset TOTP ──────────────────────────────────────────────────────────
    if ($action === 'reset_totp') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $admin['id']) { flash('Impossible de réinitialiser votre propre 2FA ici.', 'danger'); redirect('/admin/users.php'); }
        $db->prepare('UPDATE admin_users SET totp_secret=NULL, totp_enabled=0 WHERE id=?')->execute([$userId]);
        $uname = $db->prepare('SELECT username FROM admin_users WHERE id=?');
        $uname->execute([$userId]);
        $uname = $uname->fetchColumn() ?: (string)$userId;
        logActivity('reset_totp', 'admin_user', (string)$userId, "Username: {$uname}");
        flash("2FA réinitialisée pour \"{$uname}\". Sera forcée à la prochaine connexion.", 'warning');
        redirect('/admin/users.php');
    }

    // ── Activer / Désactiver ────────────────────────────────────────────────
    if ($action === 'toggle_active') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $admin['id']) { flash('Impossible de se désactiver soi-même.', 'danger'); redirect('/admin/users.php'); }
        $current = $db->prepare('SELECT active FROM admin_users WHERE id=?');
        $current->execute([$userId]);
        $row    = $current->fetch();
        $newVal = $row ? ($row['active'] ? 0 : 1) : 1;
        $db->prepare('UPDATE admin_users SET active=? WHERE id=?')->execute([$newVal, $userId]);
        logActivity($newVal ? 'activate_user' : 'deactivate_user', 'admin_user', (string)$userId);
        flash('Statut mis à jour.', 'info');
        redirect('/admin/users.php');
    }

    // ── Supprimer ───────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($userId === $admin['id']) { flash('Impossible de se supprimer soi-même.', 'danger'); redirect('/admin/users.php'); }
        $uname = $db->prepare('SELECT username FROM admin_users WHERE id=?');
        $uname->execute([$userId]);
        $uname = $uname->fetchColumn();
        $db->prepare('DELETE FROM admin_users WHERE id=?')->execute([$userId]);
        logActivity('delete_admin_user', 'admin_user', (string)$userId, "Username: {$uname}");
        flash("Utilisateur \"{$uname}\" supprimé.", 'warning');
        redirect('/admin/users.php');
    }
}

$users = $db->query(
    'SELECT id, username, role, active, created_at, last_login, created_by, totp_enabled FROM admin_users ORDER BY role ASC, username ASC'
)->fetchAll();

$creatorNames = [];
foreach ($users as $u) {
    if ($u['created_by']) {
        $c = $db->prepare('SELECT username FROM admin_users WHERE id=?');
        $c->execute([$u['created_by']]);
        $creatorNames[$u['created_by']] = $c->fetchColumn() ?: '—';
    }
}

$csrf = csrfToken();
ob_start(); ?>

<div style="display:grid;grid-template-columns:1fr 380px;gap:1.5rem;align-items:start;">

<!-- Liste des utilisateurs -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🔑 Comptes d'administration (<?= count($users) ?>)</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Username</th>
                <th>Rôle</th>
                <th>Statut</th>
                <th>2FA</th>
                <th>Dernière connexion</th>
                <th>Créé par</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($users as $u): $isSelf = ($u['id'] === $admin['id']); ?>
            <tr <?= $isSelf ? 'style="background:rgba(139,92,246,.05);"' : '' ?>>
                <td>
                    <strong><?= h($u['username']) ?></strong>
                    <?php if ($isSelf): ?><span class="badge badge-info" style="margin-left:.3rem;">Vous</span><?php endif; ?>
                </td>
                <td><span class="badge badge-<?= h($u['role']) ?>"><?= h($u['role']) ?></span></td>
                <td>
                    <?php if ($u['active']): ?>
                        <span class="badge badge-active">Actif</span>
                    <?php else: ?>
                        <span class="badge badge-banned">Inactif</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($u['totp_enabled']): ?>
                        <span title="2FA active" style="color:#10b981;font-size:.85rem;">✅ Actif</span>
                    <?php else: ?>
                        <span title="2FA non configurée" style="color:#f59e0b;font-size:.85rem;">⚠️ À configurer</span>
                    <?php endif; ?>
                </td>
                <td class="cell-muted"><?= $u['last_login'] ? date('d/m/Y H:i', strtotime($u['last_login'])) : 'Jamais' ?></td>
                <td class="cell-muted"><?= $u['created_by'] ? h($creatorNames[$u['created_by']] ?? '—') : '<em>Setup</em>' ?></td>
                <td>
                    <?php if (!$isSelf): ?>
                    <div class="actions">
                        <!-- Changer rôle -->
                        <button type="button" class="btn btn-ghost btn-sm"
                            onclick="showRoleModal(<?= $u['id'] ?>, '<?= h($u['username']) ?>', '<?= h($u['role']) ?>')">
                            🔄 Rôle
                        </button>
                        <!-- Reset MDP -->
                        <button type="button" class="btn btn-warning btn-sm"
                            onclick="showPasswordModal(<?= $u['id'] ?>, '<?= h($u['username']) ?>')">
                            🔑 MDP
                        </button>
                        <!-- Reset 2FA -->
                        <?php if ($u['totp_enabled']): ?>
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Réinitialiser la 2FA de <?= h(addslashes($u['username'])) ?> ? La personne devra la reconfigurer à sa prochaine connexion.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reset_totp">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm" style="background:rgba(239,68,68,.15);color:#ef4444;border:1px solid rgba(239,68,68,.3);" title="Réinitialiser la 2FA">
                                🔐 2FA
                            </button>
                        </form>
                        <?php endif; ?>
                        <!-- Toggle actif -->
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $u['active'] ? 'btn-warning' : 'btn-success' ?>"
                                title="<?= $u['active'] ? 'Désactiver' : 'Activer' ?>">
                                <?= $u['active'] ? '⏸' : '▶' ?>
                            </button>
                        </form>
                        <!-- Supprimer -->
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Supprimer l\'utilisateur <?= h(addslashes($u['username'])) ?> ?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm btn-icon">🗑️</button>
                        </form>
                    </div>
                    <?php else: ?>
                        <span class="text-muted text-sm">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Formulaire création -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">➕ Nouveau compte</span></div>
    <div class="panel-body">
        <form method="POST" autocomplete="off">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="create">
            <div class="form-group">
                <label class="form-label">Username *</label>
                <input type="text" name="username" class="form-control"
                       placeholder="ex: modo_pierre" pattern="[a-zA-Z0-9_-]{3,30}"
                       maxlength="30" required autocomplete="off">
                <div class="form-hint">3-30 chars, lettres/chiffres/-/_</div>
            </div>
            <div class="form-group">
                <label class="form-label">Rôle *</label>
                <select name="role" class="form-control">
                    <option value="modo">modo — Modérateur</option>
                    <option value="admin">admin — Administrateur</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">Mot de passe * (16 cars. min.)</label>
                <input type="password" name="password" class="form-control"
                       minlength="16" required autocomplete="new-password"
                       placeholder="••••••••••••••••">
                <div class="form-hint">Min. 16 car. · Maj · Min · Chiffre · Caractère spécial. Communiquez-le en privé.</div>
            </div>
            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">
                ➕ Créer le compte
            </button>
        </form>

        <div class="alert alert-info" style="margin-top:1.25rem;">
            <div>
                <strong>Différences de droits :</strong><br>
                <span class="badge badge-modo" style="margin:.25rem 0 .1rem;">modo</span> : voir, bannir/débannir, gérer propositions<br>
                <span class="badge badge-admin">admin</span> : tout + suppression, gestion des comptes équipe
            </div>
        </div>
    </div>
</div>

</div><!-- /grid -->

<!-- Modal Rôle -->
<div id="role-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-title">🔄 Changer le rôle</div>
        <div class="modal-body">Utilisateur : <strong id="role-username"></strong></div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_role">
            <input type="hidden" name="user_id" id="role-user-id">
            <div class="form-group">
                <label class="form-label">Nouveau rôle</label>
                <select name="role" id="role-select" class="form-control">
                    <option value="modo">modo — Modérateur</option>
                    <option value="admin">admin — Administrateur</option>
                </select>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('role-modal').style.display='none'">Annuler</button>
                <button type="submit" class="btn btn-primary">Confirmer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Reset MDP -->
<div id="password-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-title">🔑 Nouveau mot de passe</div>
        <div class="modal-body">Utilisateur : <strong id="pw-username"></strong></div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="pw-user-id">
            <div class="form-group">
                <label class="form-label">Nouveau mot de passe (16 chars min.)</label>
                <input type="password" name="new_password" class="form-control" minlength="16" required autocomplete="new-password"
                       placeholder="••••••••••••••••">
                <div class="form-hint" style="margin-top:.25rem;">Maj · Min · Chiffre · Caractère spécial</div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('password-modal').style.display='none'">Annuler</button>
                <button type="submit" class="btn btn-warning">🔑 Réinitialiser</button>
            </div>
        </form>
    </div>
</div>

<script>
function showRoleModal(id, username, currentRole) {
    document.getElementById('role-user-id').value = id;
    document.getElementById('role-username').textContent = username;
    document.getElementById('role-select').value = currentRole;
    document.getElementById('role-modal').style.display = 'flex';
}
function showPasswordModal(id, username) {
    document.getElementById('pw-user-id').value = id;
    document.getElementById('pw-username').textContent = username;
    document.getElementById('password-modal').style.display = 'flex';
}
['role-modal','password-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', e => {
        if (e.target.id === id) document.getElementById(id).style.display = 'none';
    });
});
</script>

<?php
$content = ob_get_clean();
echo adminLayout('Gestion de l\'équipe', $content);
