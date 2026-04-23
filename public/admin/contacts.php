<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin();
$db    = getDB();

// ─── Actions POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    if (in_array($action, ['read', 'resolved'], true) && $id) {
        $note = trim($_POST['admin_note'] ?? '');
        $db->prepare(
            'UPDATE contact_messages SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?'
        )->execute([$action, $note ?: null, $admin['id'], $id]);
        logActivity(
            $action === 'resolved' ? 'contact_resolved' : 'contact_read',
            'contact', (string)$id, $note
        );
        flash($action === 'resolved' ? 'Message résolu.' : 'Message marqué comme lu.', 'success');
    }

    redirect('/admin/contacts.php?status=' . urlencode($_POST['status_filter'] ?? 'new'));
}

// ─── Filtres ──────────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'new';
if (!in_array($statusFilter, ['new', 'read', 'resolved'], true)) {
    $statusFilter = 'new';
}

// ─── Compteurs ────────────────────────────────────────────────────────────────
$counts = [];
foreach (['new', 'read', 'resolved'] as $s) {
    $stmt = $db->prepare('SELECT COUNT(*) FROM contact_messages WHERE status = ?');
    $stmt->execute([$s]);
    $counts[$s] = (int)$stmt->fetchColumn();
}

// ─── Messages ─────────────────────────────────────────────────────────────────
$perPage = 30;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$cntStmt = $db->prepare('SELECT COUNT(*) FROM contact_messages WHERE status = ?');
$cntStmt->execute([$statusFilter]);
$total = (int)$cntStmt->fetchColumn();

$stmt = $db->prepare(
    'SELECT m.*, u.username AS reviewed_by_name
     FROM contact_messages m
     LEFT JOIN admin_users u ON u.id = m.reviewed_by
     WHERE m.status = ?
     ORDER BY m.created_at DESC
     LIMIT ? OFFSET ?'
);
$stmt->execute([$statusFilter, $perPage, $offset]);
$messages = $stmt->fetchAll();

$csrf = csrfToken();

$motifLabels = [
    'correction' => '✏️ Correction fiche',
    'retrait'    => '🗑️ Retrait de fiche',
    'suggestion' => '💡 Suggestion',
    'bug'        => '🐛 Bug',
    'autre'      => '💬 Autre',
];
$motifColors = [
    'correction' => '#6366f1',
    'retrait'    => '#ef4444',
    'suggestion' => '#10b981',
    'bug'        => '#f59e0b',
    'autre'      => '#6b7280',
];

ob_start(); ?>

<!-- Onglets statut -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:1px solid var(--border);padding-bottom:.75rem;flex-wrap:wrap;align-items:center;">
    <?php foreach (['new' => 'Nouveaux', 'read' => 'Lus', 'resolved' => 'Résolus'] as $s => $label): ?>
    <a href="?status=<?= $s ?>"
       class="btn btn-sm <?= $statusFilter === $s ? 'btn-primary' : 'btn-ghost' ?>">
        <?= $label ?>
        <?php if ($counts[$s] > 0): ?>
        <span style="background:<?= $statusFilter === $s ? 'rgba(255,255,255,.25)' : 'var(--primary-dim)' ?>;border-radius:8px;padding:0 5px;font-size:.7rem;margin-left:.25rem;">
            <?= $counts[$s] ?>
        </span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (empty($messages)): ?>
<div class="panel" style="text-align:center;padding:3rem;color:var(--text-muted);">
    Aucun message <?= $statusFilter === 'new' ? 'non lu' : ($statusFilter === 'read' ? 'lu' : 'résolu') ?>.
</div>
<?php else: ?>

<div style="display:flex;flex-direction:column;gap:.75rem;">
<?php foreach ($messages as $m): ?>
<div class="panel" style="padding:1.1rem 1.25rem;">
    <div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap;">

        <!-- Expéditeur + motif -->
        <div style="flex:1;min-width:200px;">
            <div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.4rem;flex-wrap:wrap;">
                <span style="font-weight:700;font-size:.9rem;">
                    <?= h($m['cached_name'] ?: '—') ?>
                </span>
                <span style="font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:4px;background:<?= ($motifColors[$m['motif']] ?? '#6b7280') ?>22;color:<?= ($motifColors[$m['motif']] ?? '#6b7280') ?>;">
                    <?= h($motifLabels[$m['motif']] ?? $m['motif']) ?>
                </span>
            </div>
            <div class="mono" style="font-size:.7rem;color:var(--text-muted);margin-bottom:.6rem;word-break:break-all;">
                <?= h(substr($m['npub'], 0, 20)) ?>…
                <?php if ($m['npub']): ?>
                <a href="/admin/profile-edit.php?npub=<?= urlencode($m['npub']) ?>" style="color:var(--primary);margin-left:.3rem;font-size:.7rem;">→ fiche</a>
                <?php endif; ?>
            </div>
            <div style="font-size:.875rem;line-height:1.65;color:var(--text);white-space:pre-wrap;word-break:break-word;"><?= h($m['message']) ?></div>
            <?php if ($m['admin_note']): ?>
            <div style="margin-top:.6rem;padding:.5rem .75rem;background:var(--surface-hover);border-radius:6px;font-size:.78rem;color:var(--text-muted);font-style:italic;">
                Note : <?= h($m['admin_note']) ?>
                <?php if ($m['reviewed_by_name']): ?>
                <span style="margin-left:.3rem;">— <?= h($m['reviewed_by_name']) ?></span>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Date + actions -->
        <div style="display:flex;flex-direction:column;gap:.5rem;min-width:180px;align-items:flex-end;">
            <div style="font-size:.72rem;color:var(--text-muted);">
                <?= date('d/m/Y H:i', strtotime($m['created_at'])) ?>
            </div>

            <?php if ($m['status'] !== 'resolved'): ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:.4rem;width:100%;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                <input type="hidden" name="status_filter" value="<?= h($statusFilter) ?>">
                <input type="text" name="admin_note" placeholder="Note interne (optionnel)"
                       class="form-control" style="font-size:.72rem;padding:.3rem .5rem;">
                <div style="display:flex;gap:.3rem;">
                    <?php if ($m['status'] === 'new'): ?>
                    <button type="submit" name="action" value="read"
                            class="btn btn-ghost btn-sm" style="flex:1;">👁 Lu</button>
                    <?php endif; ?>
                    <button type="submit" name="action" value="resolved"
                            class="btn btn-success btn-sm" style="flex:1;">✓ Résolu</button>
                </div>
            </form>
            <?php else: ?>
            <span class="badge badge-active" style="font-size:.75rem;">Résolu</span>
            <?php if ($m['reviewed_at']): ?>
            <div style="font-size:.7rem;color:var(--text-muted);"><?= date('d/m/Y', strtotime($m['reviewed_at'])) ?></div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Pagination -->
<?php $totalPages = max(1, (int)ceil($total / $perPage)); if ($totalPages > 1): ?>
<div style="display:flex;gap:.4rem;justify-content:center;margin-top:1.25rem;flex-wrap:wrap;">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="?status=<?= h($statusFilter) ?>&page=<?= $i ?>"
       class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
echo adminLayout('Messages de contact', $content);
