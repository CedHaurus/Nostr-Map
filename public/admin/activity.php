<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin();
$db    = getDB();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;

$adminFilter = $_GET['admin_id'] ?? '';
$where       = '1=1';
$params      = [];
if ($adminFilter) {
    $where    = 'a.admin_id = ?';
    $params[] = (int)$adminFilter;
}

$total = $db->prepare("SELECT COUNT(*) FROM admin_activity a WHERE {$where}");
$total->execute($params);
$totalCount = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare(
    "SELECT a.*, u.username, u.role
     FROM admin_activity a
     JOIN admin_users u ON u.id = a.admin_id
     WHERE {$where}
     ORDER BY a.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$activities = $stmt->fetchAll();

$adminUsers = $db->query('SELECT id, username FROM admin_users ORDER BY username')->fetchAll();

ob_start(); ?>

<div class="panel-header" style="background:var(--surface);border:1px solid var(--border);border-radius:12px;margin-bottom:1rem;">
    <form method="GET" class="filters-bar w-full">
        <select name="admin_id" class="form-control">
            <option value="">Tous les modérateurs</option>
            <?php foreach ($adminUsers as $u): ?>
            <option value="<?= $u['id'] ?>" <?= $adminFilter == $u['id'] ? 'selected' : '' ?>><?= h($u['username']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary">Filtrer</button>
        <a href="/admin/activity.php" class="btn btn-ghost">Reset</a>
        <span class="text-muted text-sm" style="margin-left:auto;"><?= $totalCount ?> entrée(s)</span>
    </form>
</div>

<div class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Date</th>
                <th>Moderateur</th>
                <th>Action</th>
                <th>Cible</th>
                <th>Détails</th>
            </tr></thead>
            <tbody>
            <?php foreach ($activities as $a): ?>
            <tr>
                <td class="cell-muted" style="white-space:nowrap;"><?= date('d/m/Y H:i:s', strtotime($a['created_at'])) ?></td>
                <td>
                    <strong><?= h($a['username']) ?></strong>
                    <span class="badge badge-<?= h($a['role']) ?>"><?= h($a['role']) ?></span>
                </td>
                <td><code style="background:var(--surface2);padding:.15rem .4rem;border-radius:4px;font-size:.78rem;"><?= h($a['action']) ?></code></td>
                <td>
                    <?php if ($a['target_type']): ?>
                        <span class="badge badge-info"><?= h($a['target_type']) ?></span>
                    <?php endif; ?>
                    <?php if ($a['target_id']): ?>
                        <span class="mono" style="font-size:.72rem;"><?= h(substr($a['target_id'], 0, 25)) ?><?= strlen($a['target_id']) > 25 ? '…' : '' ?></span>
                    <?php endif; ?>
                </td>
                <td class="cell-muted"><?= h($a['details'] ?: '—') ?></td>
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
            <a href="?<?= http_build_query(['admin_id'=>$adminFilter,'page'=>$i]) ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
echo adminLayout('Journal d\'activité', $content);
