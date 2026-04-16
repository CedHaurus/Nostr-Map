<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin();
$db    = getDB();

// ─── Actions POST ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Traiter / ignorer un signalement
    if (in_array($action, ['reviewed', 'dismissed'], true)) {
        $id   = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['admin_note'] ?? '');
        if ($id) {
            $db->prepare(
                'UPDATE reports SET status=?, admin_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?'
            )->execute([$action, $note ?: null, $admin['id'], $id]);
            logActivity($action === 'reviewed' ? 'report_reviewed' : 'report_dismissed', 'report', (string)$id, $note);
            flash($action === 'reviewed' ? 'Signalement traité.' : 'Signalement ignoré.', 'success');
        }

    // Bloquer une IP
    } elseif ($action === 'block_ip') {
        $ip     = trim($_POST['ip'] ?? '');
        $reason = trim($_POST['block_reason'] ?? '');
        $days   = (int)($_POST['block_days'] ?? 0);
        if ($ip && filter_var($ip, FILTER_VALIDATE_IP)) {
            $expires = $days > 0 ? date('Y-m-d H:i:s', strtotime("+{$days} days")) : null;
            $db->prepare(
                'INSERT INTO blocked_ips (ip, reason, blocked_by, expires_at)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE reason=VALUES(reason), blocked_by=VALUES(blocked_by),
                 blocked_at=NOW(), expires_at=VALUES(expires_at)'
            )->execute([$ip, $reason ?: null, $admin['id'], $expires]);
            logActivity('block_ip', 'ip', $ip, $reason);
            flash("IP {$ip} bloquée.", 'success');
        }

    // Débloquer une IP
    } elseif ($action === 'unblock_ip') {
        $ip = trim($_POST['ip'] ?? '');
        if ($ip) {
            $db->prepare('DELETE FROM blocked_ips WHERE ip = ?')->execute([$ip]);
            logActivity('unblock_ip', 'ip', $ip, '');
            flash("IP {$ip} débloquée.", 'success');
        }
    }

    $tab = in_array($action, ['block_ip','unblock_ip'], true) ? 'ips' : 'reports';
    redirect('/admin/reports.php?tab=' . $tab);
}

// ─── Onglet actif ─────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'reports';

// ─── Signalements ─────────────────────────────────────────────────────────────
$statusFilter = $_GET['status'] ?? 'pending';
$whereR  = 'WHERE 1=1';
$paramsR = [];
if (in_array($statusFilter, ['pending','reviewed','dismissed'], true)) {
    $whereR   .= ' AND r.status = ?';
    $paramsR[] = $statusFilter;
}

$totalReports = (int)$db->prepare("SELECT COUNT(*) FROM reports r {$whereR}")->execute($paramsR) ? 0 : 0;
$cntStmt = $db->prepare("SELECT COUNT(*) FROM reports r {$whereR}");
$cntStmt->execute($paramsR);
$totalReports = (int)$cntStmt->fetchColumn();

$pendingCount = (int)$db->query('SELECT COUNT(*) FROM reports WHERE status="pending"')->fetchColumn();

$perPage = 40;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$stmtR = $db->prepare(
    "SELECT r.*, p.cached_name, p.cached_avatar,
            (SELECT COUNT(*) FROM reports r2 WHERE r2.reporter_ip = r.reporter_ip) AS ip_report_count
     FROM reports r
     LEFT JOIN profiles p ON p.npub = r.npub COLLATE utf8mb4_unicode_ci
     {$whereR}
     ORDER BY r.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmtR->execute($paramsR);
$reports = $stmtR->fetchAll();

// ─── IPs bloquées ─────────────────────────────────────────────────────────────
$blockedIps = $db->query(
    'SELECT b.*, u.username AS blocked_by_name
     FROM blocked_ips b
     LEFT JOIN admin_users u ON u.id = b.blocked_by
     ORDER BY b.blocked_at DESC'
)->fetchAll();

// IPs qui ont le plus signalé (candidates au blocage)
$topIps = $db->query(
    'SELECT reporter_ip, COUNT(*) AS cnt,
            MAX(created_at) AS last_seen,
            GROUP_CONCAT(DISTINCT reason ORDER BY reason SEPARATOR ", ") AS reasons
     FROM reports
     WHERE reporter_ip IS NOT NULL AND reporter_ip != ""
     GROUP BY reporter_ip
     HAVING cnt >= 2
     ORDER BY cnt DESC
     LIMIT 20'
)->fetchAll();

$reasonLabels = [
    'usurpation' => 'Usurpation d\'identité',
    'retrait'    => 'Demande de retrait',
    'doublon'    => 'Doublon',
    'scam'       => 'Arnaque / Scam',
    'spam'       => 'Spam',
    'harcelement'=> 'Harcèlement',
    'contenu_illicite' => 'Contenu illicite',
    'autre'      => 'Autre',
];
$reasonColors = [
    'usurpation' => '#f59e0b',
    'retrait'    => '#6366f1',
    'doublon'    => '#8b5cf6',
    'scam'       => '#ef4444',
    'spam'       => '#8b5cf6',
    'harcelement'=> '#ec4899',
    'contenu_illicite' => '#ef4444',
    'autre'      => '#6b7280',
];

$csrf = csrfToken();
$blockedIpList = array_column($blockedIps, 'ip');

ob_start(); ?>

<!-- Onglets -->
<div style="display:flex;gap:.5rem;margin-bottom:1.5rem;border-bottom:1px solid var(--border);padding-bottom:.75rem;align-items:center;justify-content:space-between;flex-wrap:wrap;">
    <div style="display:flex;gap:.5rem;">
        <a href="?tab=reports&status=<?= h($statusFilter) ?>"
           class="btn btn-sm <?= $tab === 'reports' ? 'btn-primary' : 'btn-ghost' ?>">
            🚩 Signalements <?php if ($pendingCount > 0): ?><span style="background:rgba(255,255,255,.25);border-radius:8px;padding:0 5px;font-size:.7rem;"><?= $pendingCount ?></span><?php endif; ?>
        </a>
        <a href="?tab=ips" class="btn btn-sm <?= $tab === 'ips' ? 'btn-primary' : 'btn-ghost' ?>">
            🔒 IPs bloquées <?php if (count($blockedIps)): ?><span style="background:rgba(255,255,255,.25);border-radius:8px;padding:0 5px;font-size:.7rem;"><?= count($blockedIps) ?></span><?php endif; ?>
        </a>
    </div>
    <?php if ($tab === 'reports'): ?>
    <div style="display:flex;gap:.4rem;">
        <?php foreach (['pending' => 'En attente', 'reviewed' => 'Traités', 'dismissed' => 'Ignorés'] as $s => $label): ?>
        <a href="?tab=reports&status=<?= $s ?>" class="btn btn-sm <?= $statusFilter === $s ? 'btn-primary' : 'btn-ghost' ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php if ($tab === 'reports'): ?>
<!-- ══ JOURNAL DES SIGNALEMENTS ══════════════════════════════════════════════ -->

<?php if (empty($reports)): ?>
<div class="panel" style="text-align:center;padding:3rem;color:var(--text-muted);">
    Aucun signalement<?= $statusFilter === 'pending' ? ' en attente' : '' ?>.
</div>
<?php else: ?>
<div style="display:flex;flex-direction:column;gap:.6rem;">
<?php foreach ($reports as $r):
    $isBlocked = in_array($r['reporter_ip'], $blockedIpList);
?>
<div class="panel" style="padding:1rem 1.25rem;">
    <div style="display:flex;gap:1rem;align-items:flex-start;flex-wrap:wrap;">

        <!-- Profil signalé -->
        <div style="display:flex;align-items:center;gap:.65rem;min-width:160px;">
            <?php if ($r['cached_avatar']): ?>
                <img src="<?= h($r['cached_avatar']) ?>" style="width:36px;height:36px;border-radius:50%;object-fit:cover;flex-shrink:0;" onerror="this.style.display='none'">
            <?php else: ?>
                <div style="width:36px;height:36px;border-radius:50%;background:var(--surface-hover);display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;font-size:.85rem;">
                    <?= strtoupper(substr($r['cached_name'] ?: $r['slug'] ?: '?', 0, 1)) ?>
                </div>
            <?php endif; ?>
            <div>
                <div style="font-weight:600;font-size:.85rem;"><?= h($r['cached_name'] ?: '—') ?></div>
                <?php if ($r['slug']): ?>
                <a href="/p/<?= h($r['slug']) ?>" target="_blank" style="font-size:.72rem;color:var(--text-muted);">@<?= h($r['slug']) ?></a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Motif -->
        <div style="flex:1;min-width:160px;">
            <span style="font-size:.72rem;font-weight:700;padding:2px 7px;border-radius:4px;background:<?= $reasonColors[$r['reason']] ?? '#6b7280' ?>22;color:<?= $reasonColors[$r['reason']] ?? '#6b7280' ?>;">
                <?= h($reasonLabels[$r['reason']] ?? $r['reason']) ?>
            </span>
            <?php if ($r['details']): ?>
            <p style="margin:.4rem 0 0;font-size:.78rem;color:var(--text-muted);"><?= h($r['details']) ?></p>
            <?php endif; ?>
        </div>

        <!-- IP + date -->
        <div style="font-size:.72rem;color:var(--text-muted);min-width:130px;">
            <div style="margin-bottom:.2rem;"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></div>
            <div style="display:flex;align-items:center;gap:.35rem;flex-wrap:wrap;">
                <span style="font-family:monospace;background:var(--surface-hover);padding:1px 5px;border-radius:4px;<?= $isBlocked ? 'color:var(--danger);' : '' ?>">
                    <?= h($r['reporter_ip'] ?? '—') ?>
                </span>
                <?php if ($r['reporter_ip'] && (int)$r['ip_report_count'] > 1): ?>
                <span style="color:var(--warning);font-weight:600;"><?= (int)$r['ip_report_count'] ?>× signalements</span>
                <?php endif; ?>
                <?php if ($isBlocked): ?>
                <span style="color:var(--danger);font-weight:600;">bloquée</span>
                <?php endif; ?>
            </div>
            <?php if ($r['admin_note']): ?>
            <div style="margin-top:.3rem;color:var(--text);font-style:italic;"><?= h($r['admin_note']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Actions -->
        <div style="display:flex;flex-direction:column;gap:.4rem;min-width:200px;">
            <?php if ($r['status'] === 'pending'): ?>
            <form method="POST" style="display:flex;flex-direction:column;gap:.4rem;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="text" name="admin_note" placeholder="Note (optionnel)" class="form-control" style="font-size:.72rem;padding:.3rem .5rem;">
                <div style="display:flex;gap:.3rem;">
                    <button type="submit" name="action" value="reviewed" class="btn btn-success btn-sm" style="flex:1;">✓ Traité</button>
                    <button type="submit" name="action" value="dismissed" class="btn btn-ghost btn-sm" style="flex:1;">✕ Ignorer</button>
                </div>
            </form>
            <?php else: ?>
            <span class="badge badge-<?= $r['status'] === 'reviewed' ? 'active' : 'pending' ?>" style="align-self:flex-start;">
                <?= $r['status'] === 'reviewed' ? 'Traité' : 'Ignoré' ?>
            </span>
            <?php endif; ?>

            <?php if ($r['reporter_ip'] && !$isBlocked): ?>
            <form method="POST" style="display:flex;gap:.3rem;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="block_ip">
                <input type="hidden" name="ip" value="<?= h($r['reporter_ip']) ?>">
                <input type="hidden" name="block_reason" value="Signalement abusif">
                <input type="hidden" name="block_days" value="30">
                <button type="submit" class="btn btn-danger btn-sm" style="font-size:.7rem;">
                    🚫 Bloquer IP (30j)
                </button>
            </form>
            <?php elseif ($r['reporter_ip'] && $isBlocked): ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="unblock_ip">
                <input type="hidden" name="ip" value="<?= h($r['reporter_ip']) ?>">
                <button type="submit" class="btn btn-ghost btn-sm" style="font-size:.7rem;">↩ Débloquer IP</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- Pagination -->
<?php $totalPages = max(1, (int)ceil($totalReports / $perPage)); if ($totalPages > 1): ?>
<div style="display:flex;gap:.4rem;justify-content:center;margin-top:1.25rem;flex-wrap:wrap;">
    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
    <a href="?tab=reports&status=<?= h($statusFilter) ?>&page=<?= $i ?>"
       class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-ghost' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php else: ?>
<!-- ══ IPs BLOQUÉES ══════════════════════════════════════════════════════════ -->

<!-- Bloquer manuellement -->
<div class="panel" style="padding:1.25rem;margin-bottom:1.25rem;">
    <div style="font-weight:600;font-size:.9rem;margin-bottom:.75rem;">Bloquer une IP manuellement</div>
    <form method="POST" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;">
        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
        <input type="hidden" name="action" value="block_ip">
        <div style="flex:1;min-width:140px;">
            <label class="form-label" style="font-size:.75rem;">Adresse IP</label>
            <input type="text" name="ip" class="form-control mono" placeholder="1.2.3.4" required style="font-size:.82rem;">
        </div>
        <div style="flex:2;min-width:160px;">
            <label class="form-label" style="font-size:.75rem;">Raison</label>
            <input type="text" name="block_reason" class="form-control" placeholder="Spam, abus…" style="font-size:.82rem;">
        </div>
        <div style="min-width:100px;">
            <label class="form-label" style="font-size:.75rem;">Durée (jours, 0=permanent)</label>
            <input type="number" name="block_days" class="form-control" value="30" min="0" style="font-size:.82rem;">
        </div>
        <button type="submit" class="btn btn-danger">🚫 Bloquer</button>
    </form>
</div>

<!-- IPs actives -->
<?php if (empty($blockedIps)): ?>
<div class="panel" style="text-align:center;padding:2rem;color:var(--text-muted);">Aucune IP bloquée.</div>
<?php else: ?>
<div class="panel" style="padding:0;overflow:hidden;">
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="border-bottom:1px solid var(--border);">
                <th style="padding:.65rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">IP</th>
                <th style="padding:.65rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">Raison</th>
                <th style="padding:.65rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">Bloquée par</th>
                <th style="padding:.65rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">Bloquée le</th>
                <th style="padding:.65rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">Expire</th>
                <th style="padding:.65rem 1rem;"></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($blockedIps as $b):
            $expired = $b['expires_at'] && strtotime($b['expires_at']) < time();
        ?>
        <tr style="border-bottom:1px solid var(--border);<?= $expired ? 'opacity:.5;' : '' ?>">
            <td style="padding:.6rem 1rem;font-family:monospace;font-size:.82rem;"><?= h($b['ip']) ?></td>
            <td style="padding:.6rem 1rem;font-size:.82rem;color:var(--text-muted);"><?= h($b['reason'] ?: '—') ?></td>
            <td style="padding:.6rem 1rem;font-size:.82rem;color:var(--text-muted);"><?= h($b['blocked_by_name'] ?? '—') ?></td>
            <td style="padding:.6rem 1rem;font-size:.75rem;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($b['blocked_at'])) ?></td>
            <td style="padding:.6rem 1rem;font-size:.75rem;color:var(--text-muted);">
                <?php if (!$b['expires_at']): ?>
                    <span style="color:var(--danger);">Permanent</span>
                <?php elseif ($expired): ?>
                    <span style="color:var(--text-muted);">Expiré</span>
                <?php else: ?>
                    <?= date('d/m/Y', strtotime($b['expires_at'])) ?>
                <?php endif; ?>
            </td>
            <td style="padding:.6rem 1rem;">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="unblock_ip">
                    <input type="hidden" name="ip" value="<?= h($b['ip']) ?>">
                    <button type="submit" class="btn btn-ghost btn-sm">↩ Débloquer</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- IPs suspectes (multi-signalements) -->
<?php if (!empty($topIps)): ?>
<div style="margin-top:1.5rem;">
    <div style="font-weight:600;font-size:.85rem;margin-bottom:.65rem;color:var(--text-muted);">IPs avec plusieurs signalements</div>
    <div class="panel" style="padding:0;overflow:hidden;">
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="border-bottom:1px solid var(--border);">
                    <th style="padding:.6rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">IP</th>
                    <th style="padding:.6rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">Nb signalements</th>
                    <th style="padding:.6rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">Motifs</th>
                    <th style="padding:.6rem 1rem;text-align:left;font-size:.75rem;color:var(--text-muted);">Dernière activité</th>
                    <th style="padding:.6rem 1rem;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($topIps as $t):
                $alreadyBlocked = in_array($t['reporter_ip'], $blockedIpList);
            ?>
            <tr style="border-bottom:1px solid var(--border);">
                <td style="padding:.55rem 1rem;font-family:monospace;font-size:.82rem;"><?= h($t['reporter_ip']) ?></td>
                <td style="padding:.55rem 1rem;">
                    <span style="font-weight:700;color:var(--danger);"><?= (int)$t['cnt'] ?></span>
                </td>
                <td style="padding:.55rem 1rem;font-size:.75rem;color:var(--text-muted);"><?= h($t['reasons']) ?></td>
                <td style="padding:.55rem 1rem;font-size:.75rem;color:var(--text-muted);"><?= date('d/m/Y H:i', strtotime($t['last_seen'])) ?></td>
                <td style="padding:.55rem 1rem;">
                    <?php if (!$alreadyBlocked): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="block_ip">
                        <input type="hidden" name="ip" value="<?= h($t['reporter_ip']) ?>">
                        <input type="hidden" name="block_reason" value="Multi-signalements (<?= (int)$t['cnt'] ?>×)">
                        <input type="hidden" name="block_days" value="30">
                        <button type="submit" class="btn btn-danger btn-sm">🚫 Bloquer</button>
                    </form>
                    <?php else: ?>
                    <span class="badge badge-banned" style="font-size:.7rem;">Bloquée</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php
$content = ob_get_clean();
echo adminLayout('Signalements', $content);
