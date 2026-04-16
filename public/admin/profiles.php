<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin();
$db    = getDB();

// ─── Actions rapides POST ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $npub   = trim($_POST['npub'] ?? '');

    if ($npub) {
        // Vérifier si le profil est sanctuarisé
        $chk = $db->prepare('SELECT protected FROM profiles WHERE npub=?');
        $chk->execute([$npub]);
        $isProtected = (bool)($chk->fetchColumn());

        if ($isProtected) {
            flash('Ce profil est sanctuarisé — aucune action de modération n\'est possible.', 'danger');
        } else {
            switch ($action) {
                case 'ban':
                    $reason = trim($_POST['reason'] ?? 'Modération');
                    $db->prepare('UPDATE profiles SET status="banned", banned_reason=? WHERE npub=?')
                       ->execute([$reason, $npub]);
                    logActivity('ban', 'profile', $npub, $reason);
                    flash("Profil banni : {$npub}", 'warning');
                    break;

                case 'unban':
                    $db->prepare('UPDATE profiles SET status="active", banned_reason=NULL WHERE npub=?')
                       ->execute([$npub]);
                    logActivity('unban', 'profile', $npub);
                    flash('Profil réactivé.', 'success');
                    break;

                case 'request_delete':
                    $db->prepare(
                        'UPDATE profiles SET status="pending_deletion",
                         deletion_requested_by=?, deletion_requested_at=NOW()
                         WHERE npub=?'
                    )->execute([$admin['id'], $npub]);
                    logActivity('request_delete', 'profile', $npub, 'En attente d\'approbation admin');
                    flash('Demande de suppression envoyée à l\'admin.', 'warning');
                    break;

                case 'delete':
                    if (!isRole('admin')) {
                        flash('Suppression réservée aux admins.', 'danger');
                        break;
                    }
                    $slug = $db->prepare('SELECT slug FROM profiles WHERE npub=?');
                    $slug->execute([$npub]);
                    $s = $slug->fetchColumn();
                    $db->prepare('DELETE FROM social_links WHERE npub=?')->execute([$npub]);
                    $db->prepare('DELETE FROM user_activity WHERE npub=?')->execute([$npub]);
                    $db->prepare('DELETE FROM profiles WHERE npub=?')->execute([$npub]);
                    logActivity('delete', 'profile', $npub, "slug: {$s}");
                    flash('Profil supprimé définitivement.', 'success');
                    break;

                case 'pending':
                    $db->prepare('UPDATE profiles SET status="pending" WHERE npub=?')
                       ->execute([$npub]);
                    logActivity('set_pending', 'profile', $npub);
                    flash('Profil passé en attente.', 'info');
                    break;
            }
        }
    }
    redirect('/admin/profiles.php?' . http_build_query(['status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? '', 'page' => $_GET['page'] ?? 1]));
}

// ─── Filtres & pagination ──────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? '';
$q            = trim($_GET['q'] ?? '');
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

$validStatuses = ['active','pending','banned'];
if ($statusFilter && in_array($statusFilter, $validStatuses)) {
    $where[]  = 'p.status = ?';
    $params[] = $statusFilter;
} else {
    // Par défaut : exclure les fiches en attente de suppression
    $where[] = 'p.status != "pending_deletion"';
}
if ($q) {
    $where[]  = '(p.cached_name LIKE ? OR p.slug LIKE ? OR p.cached_nip05 LIKE ? OR p.npub LIKE ?)';
    $like     = '%' . $q . '%';
    $params   = array_merge($params, [$like, $like, $like, $like]);
}

$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM profiles p WHERE {$whereStr}");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare(
    "SELECT p.npub, p.slug, p.cached_name, p.cached_avatar, p.cached_nip05,
            p.status, p.registered_at, p.last_login, p.banned_reason,
            (SELECT COUNT(*) FROM social_links sl WHERE sl.npub=p.npub) AS links_count,
            (SELECT COUNT(*) FROM social_links sl WHERE sl.npub=p.npub AND sl.verified=1) AS verified_count
     FROM profiles p
     WHERE {$whereStr}
     ORDER BY p.registered_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$profiles = $stmt->fetchAll();

$csrf = csrfToken();

ob_start(); ?>

<!-- Filtres -->
<div class="panel-header" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:1rem;">
    <form method="GET" class="filters-bar w-full">
        <input type="text" name="q" class="form-control search-input"
               placeholder="Rechercher nom, slug, npub…"
               value="<?= h($q) ?>">
        <select name="status" class="form-control">
            <option value="">Tous les statuts</option>
            <option value="active"  <?= $statusFilter==='active'  ? 'selected':'' ?>>Actifs</option>
            <option value="pending" <?= $statusFilter==='pending' ? 'selected':'' ?>>En attente</option>
            <option value="banned"  <?= $statusFilter==='banned'  ? 'selected':'' ?>>Bannis</option>
        </select>
        <button type="submit" class="btn btn-primary">Filtrer</button>
        <a href="/admin/profiles.php" class="btn btn-ghost">Reset</a>
        <span class="text-muted text-sm" style="margin-left:auto;"><?= $totalCount ?> profil(s)</span>
    </form>
</div>

<!-- Table profils -->
<div class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Profil</th>
                <th>NIP-05</th>
                <th>Statut</th>
                <th>Liens</th>
                <th>Inscrit le</th>
                <th>Dernière connexion</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($profiles as $p): ?>
            <tr>
                <td>
                    <div class="td-avatar">
                        <?php if ($p['cached_avatar']): ?>
                            <img src="<?= h($p['cached_avatar']) ?>" class="avatar-sm" alt="" onerror="this.style.display='none'">
                        <?php else: ?>
                            <div class="avatar-placeholder-sm"><?= strtoupper(substr($p['cached_name'] ?: $p['slug'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div>
                            <div class="cell-name">
                                <?= h($p['cached_name'] ?: '—') ?>
                                <?php if (!$p['last_login']): ?>
                                    <span title="Ajouté manuellement, pas encore revendiqué" style="font-size:.65rem;font-weight:600;letter-spacing:.03em;background:rgba(99,102,241,.15);color:#818cf8;border:1px solid rgba(99,102,241,.3);border-radius:4px;padding:1px 5px;vertical-align:middle;margin-left:4px;">Manuel</span>
                                <?php endif; ?>
                            </div>
                            <div class="cell-muted">@<?= h($p['slug']) ?></div>
                        </div>
                    </div>
                </td>
                <td class="cell-muted"><?= $p['cached_nip05'] ? h($p['cached_nip05']) : '—' ?></td>
                <td>
                    <span class="badge badge-<?= h($p['status']) ?>"><?= h($p['status']) ?></span>
                    <?php if ($p['banned_reason']): ?>
                        <div class="cell-muted" title="<?= h($p['banned_reason']) ?>" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= h($p['banned_reason']) ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="text-success"><?= (int)$p['verified_count'] ?>✓</span>
                    <span class="text-muted"> / <?= (int)$p['links_count'] ?></span>
                </td>
                <td class="cell-muted"><?= date('d/m/Y', strtotime($p['registered_at'])) ?></td>
                <td class="cell-muted"><?= $p['last_login'] ? date('d/m H:i', strtotime($p['last_login'])) : '—' ?></td>
                <td>
                    <div class="actions">
                        <a href="/admin/profile-edit.php?npub=<?= urlencode($p['npub']) ?>" class="btn btn-ghost btn-sm" title="Modifier">✏️ Modifier</a>

                        <?php if ($p['status'] !== 'banned'): ?>
                        <button type="button" class="btn btn-warning btn-sm"
                            onclick="showBanModal('<?= h(addslashes($p['npub'])) ?>', '<?= h(addslashes($p['cached_name'] ?: $p['slug'])) ?>')">
                            🚫 Bannir
                        </button>
                        <?php else: ?>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                            <input type="hidden" name="npub" value="<?= h($p['npub']) ?>">
                            <input type="hidden" name="action" value="unban">
                            <button type="submit" class="btn btn-success btn-sm">✅ Débannir</button>
                        </form>
                        <?php endif; ?>

                        <?php if (isRole('admin')): ?>
                        <button type="button" class="btn btn-danger btn-sm"
                            onclick="confirmDelete('<?= h(addslashes($p['npub'])) ?>', '<?= h(addslashes($p['cached_name'] ?: $p['slug'])) ?>')">
                            🗑️
                        </button>
                        <?php else: ?>
                        <button type="button" class="btn btn-danger btn-sm" title="Demander la suppression"
                            onclick="confirmRequestDelete('<?= h(addslashes($p['npub'])) ?>', '<?= h(addslashes($p['cached_name'] ?: $p['slug'])) ?>')">
                            🗑️
                        </button>
                        <?php endif; ?>

                        <a href="/p/<?= h($p['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Voir le profil public">↗</a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($profiles)): ?>
            <tr><td colspan="8"><div class="empty-state"><div class="empty-state-icon">👻</div><p>Aucun profil trouvé.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <span class="pagination-info">Page <?= $page ?> / <?= $totalPages ?> (<?= $totalCount ?> résultats)</span>
        <?php for ($i = max(1, $page-3); $i <= min($totalPages, $page+3); $i++): ?>
            <a href="?<?= http_build_query(['q'=>$q,'status'=>$statusFilter,'page'=>$i]) ?>"
               class="page-btn <?= $i===$page ? 'active' : '' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Modal Ban -->
<div id="ban-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-title">🚫 Bannir le profil</div>
        <div class="modal-body">
            Profil : <strong id="ban-name"></strong><br>
            Indiquez la raison du bannissement (visible par l'équipe).
        </div>
        <form method="POST" id="ban-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="ban">
            <input type="hidden" name="npub" id="ban-npub">
            <div class="form-group">
                <input type="text" name="reason" class="form-control" placeholder="Raison du bannissement…" required>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeBanModal()">Annuler</button>
                <button type="submit" class="btn btn-danger">🚫 Confirmer le bannissement</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Delete -->
<div id="delete-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-title">⚠️ Suppression définitive</div>
        <div class="modal-body">
            Vous allez supprimer définitivement le profil <strong id="delete-name"></strong>
            ainsi que tous ses liens RS. Cette action est <strong style="color:var(--danger)">irréversible</strong>.
        </div>
        <form method="POST" id="delete-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="npub" id="delete-npub">
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="closeDeleteModal()">Annuler</button>
                <button type="submit" class="btn btn-danger">🗑️ Supprimer définitivement</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal Demande suppression (modo) -->
<div id="reqdelete-modal" class="modal-overlay" style="display:none;">
    <div class="modal-box">
        <div class="modal-title">🗑️ Demander la suppression</div>
        <div class="modal-body">
            La fiche <strong id="reqdelete-name"></strong> sera masquée et soumise à l'admin pour approbation définitive ou restauration.
        </div>
        <form method="POST" id="reqdelete-form">
            <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="request_delete">
            <input type="hidden" name="npub" id="reqdelete-npub">
            <div class="modal-actions">
                <button type="button" class="btn btn-ghost" onclick="document.getElementById('reqdelete-modal').style.display='none'">Annuler</button>
                <button type="submit" class="btn btn-danger">Envoyer la demande</button>
            </div>
        </form>
    </div>
</div>

<script>
function showBanModal(npub, name) {
    document.getElementById('ban-npub').value = npub;
    document.getElementById('ban-name').textContent = name;
    document.getElementById('ban-modal').style.display = 'flex';
}
function closeBanModal() { document.getElementById('ban-modal').style.display = 'none'; }

function confirmDelete(npub, name) {
    document.getElementById('delete-npub').value = npub;
    document.getElementById('delete-name').textContent = name;
    document.getElementById('delete-modal').style.display = 'flex';
}
function closeDeleteModal() { document.getElementById('delete-modal').style.display = 'none'; }

function confirmRequestDelete(npub, name) {
    document.getElementById('reqdelete-npub').value = npub;
    document.getElementById('reqdelete-name').textContent = name;
    document.getElementById('reqdelete-modal').style.display = 'flex';
}

// Fermer sur clic hors modal
['ban-modal','delete-modal','reqdelete-modal'].forEach(id => {
    document.getElementById(id).addEventListener('click', function(e) {
        if (e.target === this) this.style.display = 'none';
    });
});
</script>

<?php
$content = ob_get_clean();
echo adminLayout('Gestion des profils', $content);
