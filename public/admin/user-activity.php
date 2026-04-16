<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin();
$db    = getDB();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$actionFilter = trim($_GET['action'] ?? '');
$npubFilter   = trim($_GET['npub'] ?? '');

$where  = ['1=1'];
$params = [];

if ($actionFilter) {
    $where[]  = 'action = ?';
    $params[] = $actionFilter;
}
if ($npubFilter) {
    $where[]  = 'npub LIKE ?';
    $params[] = '%' . $npubFilter . '%';
}

$whereStr = implode(' AND ', $where);

$total = $db->prepare("SELECT COUNT(*) FROM user_activity WHERE {$whereStr}");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare(
    "SELECT ua.*, p.slug, p.cached_name
     FROM user_activity ua
     LEFT JOIN profiles p ON p.npub = ua.npub
     WHERE {$whereStr}
     ORDER BY ua.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// Compteurs par action
$actionCounts = $db->query(
    "SELECT action, COUNT(*) as cnt FROM user_activity GROUP BY action ORDER BY cnt DESC LIMIT 20"
)->fetchAll();

$actionLabels = [
    'login'          => ['Login',            'badge-info'],
    'register'       => ['Inscription',      'badge-active'],
    'update_profile' => ['Màj profil',       'badge-modo'],
    'add_link'       => ['Ajout lien',       'badge-admin'],
    'delete_link'    => ['Suppr. lien',      'badge-warning'],
    'verify_link'    => ['Vérif. lien',      'badge-verified'],
];

ob_start(); ?>

<div class="panel-header" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:1rem;">
    <form method="GET" class="filters-bar w-full" style="flex-wrap:wrap;gap:.75rem;">
        <select name="action" class="form-control" style="max-width:180px;">
            <option value="">Toutes les actions</option>
            <?php foreach ($actionLabels as $key => [$label, $badge]): ?>
            <option value="<?= h($key) ?>" <?= $actionFilter === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="npub" class="form-control" style="max-width:260px;"
               placeholder="npub ou partie…" value="<?= h($npubFilter) ?>">
        <button type="submit" class="btn btn-primary">Filtrer</button>
        <a href="/admin/user-activity.php" class="btn btn-ghost">Reset</a>
        <span class="text-muted text-sm" style="margin-left:auto;"><?= $totalCount ?> entrée(s)</span>
    </form>
</div>

<!-- Résumé par action -->
<div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;">
    <?php foreach ($actionCounts as $ac): ?>
    <?php $label = $actionLabels[$ac['action']] ?? [$ac['action'], 'badge-info']; ?>
    <a href="?action=<?= urlencode($ac['action']) ?>"
       class="badge <?= $label[1] ?>"
       style="font-size:.8rem;padding:.3rem .6rem;text-decoration:none;">
        <?= h($label[0]) ?> <strong><?= $ac['cnt'] ?></strong>
    </a>
    <?php endforeach; ?>
</div>

<div class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Date</th>
                <th>Utilisateur</th>
                <th>Action</th>
                <th>Cible</th>
                <th>Détails</th>
            </tr></thead>
            <tbody>
            <?php foreach ($activities as $a): ?>
            <?php $label = $actionLabels[$a['action']] ?? [$a['action'], 'badge-info']; ?>
            <tr>
                <td class="cell-muted" style="white-space:nowrap;"><?= date('d/m/Y H:i:s', strtotime($a['created_at'])) ?></td>
                <td>
                    <?php if ($a['slug']): ?>
                        <a href="/admin/profile-edit.php?npub=<?= urlencode($a['npub']) ?>" class="cell-name">
                            <?= h($a['cached_name'] ?: '@' . $a['slug']) ?>
                        </a>
                        <div class="cell-muted">@<?= h($a['slug']) ?></div>
                    <?php else: ?>
                        <span class="mono" style="font-size:.7rem;"><?= h(substr($a['npub'], 0, 30)) ?>…</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge <?= $label[1] ?>" style="font-size:.75rem;"><?= h($label[0]) ?></span>
                </td>
                <td>
                    <?php if ($a['target_type']): ?>
                        <span class="badge badge-info" style="font-size:.72rem;"><?= h($a['target_type']) ?></span>
                    <?php endif; ?>
                    <?php if ($a['target_id'] && $a['target_id'] !== $a['npub']): ?>
                        <span class="mono" style="font-size:.7rem;"><?= h(substr($a['target_id'], 0, 20)) ?><?= strlen($a['target_id']) > 20 ? '…' : '' ?></span>
                    <?php endif; ?>
                </td>
                <td class="cell-muted" style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($a['details'] ?? '') ?>">
                    <?= h($a['details'] ?: '—') ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($activities)): ?>
            <tr><td colspan="5"><div class="empty-state"><p>Aucune activité enregistrée.</p></div></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <span class="pagination-info">Page <?= $page ?> / <?= $totalPages ?></span>
        <?php for ($i = max(1,$page-3); $i <= min($totalPages,$page+3); $i++): ?>
            <a href="?<?= http_build_query(['action'=>$actionFilter,'npub'=>$npubFilter,'page'=>$i]) ?>"
               class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
echo adminLayout('Journal annuaire', $content);
