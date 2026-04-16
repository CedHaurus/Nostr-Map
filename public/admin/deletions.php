<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin('admin'); // admin uniquement
$db    = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $npub   = trim($_POST['npub'] ?? '');

    if ($npub) {
        if ($action === 'approve') {
            // Suppression définitive : liens, activité, puis profil
            $slug = $db->prepare('SELECT slug FROM profiles WHERE npub=?');
            $slug->execute([$npub]);
            $s = $slug->fetchColumn();
            $db->prepare('DELETE FROM social_links   WHERE npub=?')->execute([$npub]);
            $db->prepare('DELETE FROM user_activity  WHERE npub=?')->execute([$npub]);
            $db->prepare('DELETE FROM profiles       WHERE npub=?')->execute([$npub]);
            logActivity('delete_approved', 'profile', $npub, "slug: {$s}");
            flash('Profil supprimé définitivement.', 'success');

        } elseif ($action === 'restore') {
            $db->prepare(
                'UPDATE profiles SET status="active",
                 deletion_requested_by=NULL, deletion_requested_at=NULL
                 WHERE npub=?'
            )->execute([$npub]);
            logActivity('restore_profile', 'profile', $npub, 'Restauré depuis la file de suppression');
            flash('Profil restauré et remis en ligne.', 'success');
        }
    }
    redirect('/admin/deletions.php');
}

$stmt = $db->prepare(
    "SELECT p.*,
            a.username AS requester
     FROM profiles p
     LEFT JOIN admin_users a ON a.id = p.deletion_requested_by
     WHERE p.status = 'pending_deletion'
     ORDER BY p.deletion_requested_at DESC"
);
$stmt->execute();
$pending = $stmt->fetchAll();

$csrf = csrfToken();
ob_start(); ?>

<?php if (empty($pending)): ?>
<div class="panel">
    <div class="empty-state" style="padding:3rem 0;">
        <div class="empty-state-icon">✅</div>
        <p>Aucune suppression en attente.</p>
    </div>
</div>
<?php else: ?>
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🗑️ Fiches en attente de suppression (<?= count($pending) ?>)</span>
        <span class="text-muted text-sm">Approuver = suppression définitive · Restaurer = remise en ligne</span>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Profil</th>
                <th>Demandé par</th>
                <th>Le</th>
                <th>Liens RS</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($pending as $p): ?>
            <tr style="background:rgba(239,68,68,.04);">
                <td>
                    <div class="td-avatar">
                        <?php if ($p['cached_avatar']): ?>
                            <img src="<?= h($p['cached_avatar']) ?>" class="avatar-sm" alt="" onerror="this.style.display='none'">
                        <?php else: ?>
                            <div class="avatar-placeholder-sm"><?= strtoupper(substr($p['cached_name'] ?: $p['slug'], 0, 1)) ?></div>
                        <?php endif; ?>
                        <div>
                            <div class="cell-name"><?= h($p['cached_name'] ?: '—') ?></div>
                            <div class="cell-muted">@<?= h($p['slug']) ?></div>
                            <?php if ($p['cached_nip05']): ?>
                            <div class="cell-muted" style="font-size:.72rem;"><?= h($p['cached_nip05']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <?php if (($p['deletion_source'] ?? 'admin') === 'user'): ?>
                        <strong>Utilisateur</strong>
                        <span class="badge" style="font-size:.7rem;background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3);">auto</span>
                    <?php else: ?>
                        <strong><?= h($p['requester'] ?? '—') ?></strong>
                        <span class="badge badge-modo" style="font-size:.7rem;">modo</span>
                    <?php endif; ?>
                </td>
                <td class="cell-muted"><?= $p['deletion_requested_at'] ? date('d/m/Y H:i', strtotime($p['deletion_requested_at'])) : '—' ?></td>
                <td class="cell-muted">
                    <?php
                    $lc = $db->prepare('SELECT COUNT(*) FROM social_links WHERE npub=?');
                    $lc->execute([$p['npub']]);
                    echo (int)$lc->fetchColumn();
                    ?> lien(s)
                </td>
                <td>
                    <div class="actions">
                        <a href="/admin/profile-edit.php?npub=<?= urlencode($p['npub']) ?>" class="btn btn-ghost btn-sm">✏️ Voir</a>

                        <!-- Restaurer -->
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="restore">
                            <input type="hidden" name="npub" value="<?= h($p['npub']) ?>">
                            <button type="submit" class="btn btn-success btn-sm">↩️ Restaurer</button>
                        </form>

                        <!-- Approuver (supprimer définitivement) -->
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Supprimer définitivement @<?= h(addslashes($p['slug'])) ?> ? Action irréversible.')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="npub" value="<?= h($p['npub']) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">🗑️ Supprimer</button>
                        </form>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
echo adminLayout('Suppressions en attente', $content);
