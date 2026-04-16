<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin();
$db    = getDB();

// ─── Statistiques ──────────────────────────────────────────────────────────
$stats = [];
$stats['total_profiles']  = $db->query('SELECT COUNT(*) FROM profiles')->fetchColumn();
$stats['active_profiles'] = $db->query('SELECT COUNT(*) FROM profiles WHERE status="active"')->fetchColumn();
$stats['banned_profiles'] = $db->query('SELECT COUNT(*) FROM profiles WHERE status="banned"')->fetchColumn();
$stats['total_links']     = $db->query('SELECT COUNT(*) FROM social_links')->fetchColumn();
$stats['verified_links']  = $db->query('SELECT COUNT(*) FROM social_links WHERE verified=1')->fetchColumn();
$stats['proposals_pending']= $db->query('SELECT COUNT(*) FROM proposals WHERE status="pending"')->fetchColumn();
$stats['new_today']       = $db->query('SELECT COUNT(*) FROM profiles WHERE DATE(registered_at)=CURDATE()')->fetchColumn();
$stats['new_week']        = $db->query('SELECT COUNT(*) FROM profiles WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)')->fetchColumn();

// ─── Derniers profils inscrits ──────────────────────────────────────────────
$recent = $db->query(
    'SELECT npub, slug, cached_name, cached_avatar, status, registered_at
     FROM profiles ORDER BY registered_at DESC LIMIT 8'
)->fetchAll();

// ─── Dernières activités ────────────────────────────────────────────────────
$activities = $db->query(
    'SELECT a.action, a.target_type, a.target_id, a.details, a.created_at,
            u.username, u.role
     FROM admin_activity a
     JOIN admin_users u ON u.id = a.admin_id
     ORDER BY a.created_at DESC LIMIT 15'
)->fetchAll();

// ─── Propositions en attente ────────────────────────────────────────────────
$proposals = $db->query(
    'SELECT p.id, p.npub_proposed, p.proposed_by, p.message, p.created_at,
            pr.slug, pr.cached_name
     FROM proposals p
     LEFT JOIN profiles pr ON pr.npub = p.npub_proposed
     WHERE p.status = "pending"
     ORDER BY p.created_at DESC LIMIT 5'
)->fetchAll();

// ─── Génération HTML ─────────────────────────────────────────────────────────

ob_start(); ?>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">👥</div>
        <div class="stat-value"><?= (int)$stats['total_profiles'] ?></div>
        <div class="stat-label">Profils total</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">✅</div>
        <div class="stat-value"><?= (int)$stats['active_profiles'] ?></div>
        <div class="stat-label">Actifs</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📬</div>
        <div class="stat-value"><?= (int)$stats['proposals_pending'] ?></div>
        <div class="stat-label">Propositions en attente</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🔗</div>
        <div class="stat-value"><?= (int)$stats['verified_links'] ?></div>
        <div class="stat-label">Liens vérifiés</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">🆕</div>
        <div class="stat-value"><?= (int)$stats['new_today'] ?></div>
        <div class="stat-label">Inscrits aujourd'hui</div>
    </div>
    <div class="stat-card">
        <div class="stat-icon">📅</div>
        <div class="stat-value"><?= (int)$stats['new_week'] ?></div>
        <div class="stat-label">Inscrits cette semaine</div>
    </div>
</div>

<div class="dashboard-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

    <!-- Derniers inscrits -->
    <div class="panel">
        <div class="panel-header">
            <span class="panel-title">🆕 Derniers inscrits</span>
            <a href="/admin/profiles.php" class="btn btn-ghost btn-sm">Voir tous →</a>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr>
                    <th>Profil</th>
                    <th>Statut</th>
                    <th>Date</th>
                    <th></th>
                </tr></thead>
                <tbody>
                <?php foreach ($recent as $p): ?>
                <tr>
                    <td>
                        <div class="td-avatar">
                            <?php if ($p['cached_avatar']): ?>
                                <img src="<?= h($p['cached_avatar']) ?>" class="avatar-sm" alt="" onerror="this.style.display='none'">
                            <?php else: ?>
                                <div class="avatar-placeholder-sm"><?= strtoupper(substr($p['cached_name'] ?: $p['slug'], 0, 1)) ?></div>
                            <?php endif; ?>
                            <div>
                                <div class="cell-name"><?= h($p['cached_name'] ?: $p['slug']) ?></div>
                                <div class="cell-muted">@<?= h($p['slug']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge badge-<?= h($p['status']) ?>"><?= h($p['status']) ?></span></td>
                    <td class="cell-muted"><?= date('d/m H:i', strtotime($p['registered_at'])) ?></td>
                    <td>
                        <a href="/admin/profile-edit.php?npub=<?= urlencode($p['npub']) ?>" class="btn btn-ghost btn-sm btn-icon" title="Modifier">✏️</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?>
                <tr><td colspan="4"><div class="empty-state"><p>Aucun profil.</p></div></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Activité récente -->
    <div>
        <!-- Propositions en attente -->
        <?php if (!empty($proposals)): ?>
        <div class="panel" style="margin-bottom:1.5rem;">
            <div class="panel-header">
                <span class="panel-title">📬 Propositions en attente</span>
                <a href="/admin/proposals.php" class="btn btn-warning btn-sm"><?= count($proposals) ?> en attente</a>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Npub proposé</th><th>Message</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($proposals as $pr): ?>
                    <tr>
                        <td>
                            <div class="cell-name"><?= h($pr['cached_name'] ?: 'Inconnu') ?></div>
                            <div class="mono truncate"><?= h(substr($pr['npub_proposed'], 0, 20)) ?>…</div>
                        </td>
                        <td class="cell-muted truncate"><?= h($pr['message'] ?: '—') ?></td>
                        <td class="cell-muted"><?= date('d/m', strtotime($pr['created_at'])) ?></td>
                        <td><a href="/admin/proposals.php" class="btn btn-ghost btn-sm">→</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <!-- Activités -->
        <div class="panel">
            <div class="panel-header">
                <span class="panel-title">📋 Activité récente</span>
                <a href="/admin/activity.php" class="btn btn-ghost btn-sm">Tout voir →</a>
            </div>
            <div class="panel-body">
                <?php if (empty($activities)): ?>
                    <div class="empty-state"><p>Aucune activité enregistrée.</p></div>
                <?php else: ?>
                    <?php foreach ($activities as $act): ?>
                    <div class="activity-item">
                        <div class="activity-dot"></div>
                        <div class="activity-content">
                            <div class="activity-text">
                                <strong><?= h($act['username']) ?></strong>
                                <span class="badge badge-<?= h($act['role']) ?>" style="margin:0 .3rem;"><?= h($act['role']) ?></span>
                                — <?= h($act['action']) ?>
                                <?php if ($act['target_id']): ?>
                                    sur <span class="mono"><?= h(substr($act['target_id'], 0, 20)) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="activity-meta">
                                <?= date('d/m/Y H:i', strtotime($act['created_at'])) ?>
                                <?php if ($act['details']): ?> · <?= h($act['details']) ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
echo adminLayout('Dashboard', $content);
