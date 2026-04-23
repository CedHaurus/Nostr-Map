<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
require_once '/var/www/html/api/_helpers.php';
$admin = requireAdmin();
$db    = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action     = $_POST['action'] ?? '';
    $proposalId = (int)($_POST['id'] ?? 0);

    if ($proposalId && $action === 'reject') {
        $db->prepare('UPDATE proposals SET status="rejected" WHERE id=?')->execute([$proposalId]);
        logActivity('reject_proposal', 'proposal', (string)$proposalId);
        flash('Proposition rejetée.', 'info');
    }

    if ($proposalId && $action === 'accept') {
        // Récupérer la proposition
        $prop = $db->prepare('SELECT * FROM proposals WHERE id = ?');
        $prop->execute([$proposalId]);
        $prop = $prop->fetch();

        if ($prop && $prop['status'] === 'pending') {
            $npub = $prop['npub_proposed'];

            // Vérifier que le npub n'est pas déjà dans l'annuaire
            $exists = $db->prepare('SELECT 1 FROM profiles WHERE npub = ?');
            $exists->execute([$npub]);

            if ($exists->fetchColumn()) {
                flash('Ce profil existe déjà dans l\'annuaire.', 'warning');
            } else {
                // Générer le slug
                $slug = generateAdminSlug($npub, null, $db);

                // Créer le profil
                $db->prepare(
                    'INSERT INTO profiles (npub, slug, cached_name, cached_avatar, status, registered_at)
                     VALUES (?, ?, ?, ?, "active", NOW())'
                )->execute([
                    $npub,
                    $slug,
                    $prop['cached_name'] ?: null,
                    $prop['cached_avatar'] ?: null,
                ]);

                // Créer les liens sociaux (non vérifiés) si présents
                if ($prop['links_json']) {
                    $links = json_decode($prop['links_json'], true) ?: [];
                    foreach ($links as $link) {
                        $platform = $link['platform'] ?? '';
                        $url      = $link['url'] ?? '';
                        $handle   = $link['handle'] ?? '';
                        if (!$platform || !$url) continue;
                        // Générer un challenge factice (sera mis à jour lors de la vérification)
                        $challenge = bin2hex(random_bytes(16));
                        $db->prepare(
                            'INSERT IGNORE INTO social_links (npub, platform, display_handle, url, challenge, verified)
                             VALUES (?, ?, ?, ?, ?, 0)'
                        )->execute([$npub, $platform, $handle ?: null, $url, $challenge]);
                    }
                }

                // Pré-charger les métadonnées Nostr immédiatement
                warmProfileCache($npub, $db);

                // Marquer la proposition comme acceptée
                $db->prepare('UPDATE proposals SET status="accepted" WHERE id=?')->execute([$proposalId]);
                logActivity('accept_proposal', 'proposal', (string)$proposalId, "slug: {$slug}");
                flash("Proposition acceptée — fiche @{$slug} créée.", 'success');
            }
        }
    }

    redirect('/admin/proposals.php');
}

$statusFilter = $_GET['status'] ?? 'pending';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$validStatuses = ['pending','accepted','rejected'];
if (!in_array($statusFilter, $validStatuses)) $statusFilter = 'pending';

$total = $db->prepare('SELECT COUNT(*) FROM proposals WHERE status = ?');
$total->execute([$statusFilter]);
$totalCount = (int)$total->fetchColumn();
$totalPages = max(1, (int)ceil($totalCount / $perPage));

$stmt = $db->prepare(
    "SELECT p.*,
            pr.slug, pr.cached_name AS profile_name, pr.cached_avatar AS profile_avatar,
            pr.status AS profile_status
     FROM proposals p
     LEFT JOIN profiles pr ON pr.npub = p.npub_proposed
     WHERE p.status = ?
     ORDER BY p.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute([$statusFilter]);
$proposals = $stmt->fetchAll();

// Compteurs par statut
$counts = [];
foreach ($validStatuses as $s) {
    $c = $db->prepare('SELECT COUNT(*) FROM proposals WHERE status = ?');
    $c->execute([$s]);
    $counts[$s] = (int)$c->fetchColumn();
}

$csrf = csrfToken();
ob_start(); ?>

<!-- Onglets statuts -->
<div class="page-header">
    <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
        <?php foreach (['pending'=>'⏳','accepted'=>'✅','rejected'=>'❌'] as $s => $icon): ?>
        <a href="?status=<?= $s ?>" class="btn <?= $statusFilter===$s ? 'btn-primary' : 'btn-ghost' ?>">
            <?= $icon ?> <?= ucfirst($s) ?>
            <span style="margin-left:.25rem;opacity:.7;">(<?= $counts[$s] ?>)</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="panel">
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Profil proposé</th>
                <th>Proposé par</th>
                <th>Liens proposés</th>
                <th>Message</th>
                <th>Date</th>
                <th>Statut</th>
                <?php if ($statusFilter === 'pending'): ?><th>Actions</th><?php endif; ?>
            </tr></thead>
            <tbody>
            <?php foreach ($proposals as $pr):
                // Avatar : priorité profil existant, sinon données soumises
                $avatar = $pr['profile_avatar'] ?? $pr['cached_avatar'] ?? null;
                $name   = $pr['profile_name']   ?? $pr['cached_name']   ?? null;
                $links  = $pr['links_json'] ? (json_decode($pr['links_json'], true) ?: []) : [];
                $platformIcons = ['x'=>'𝕏','mastodon'=>'🐘','bluesky'=>'🦋','youtube'=>'▶️'];
            ?>
            <tr>
                <td>
                    <div class="td-avatar">
                        <?php if ($avatar): ?>
                            <img src="<?= h($avatar) ?>" class="avatar-sm" alt=""
                                 onerror="this.style.display='none'">
                        <?php else: ?>
                            <div class="avatar-placeholder-sm"><?= strtoupper(substr($name ?: '?', 0, 1)) ?></div>
                        <?php endif; ?>
                        <div>
                            <?php if ($pr['slug']): ?>
                                <a href="/admin/profile-edit.php?npub=<?= urlencode($pr['npub_proposed']) ?>" class="cell-name">
                                    <?= h($name ?: $pr['slug']) ?>
                                </a>
                                <div class="cell-muted">@<?= h($pr['slug']) ?></div>
                            <?php elseif ($name): ?>
                                <div class="cell-name"><?= h($name) ?></div>
                                <div class="cell-muted mono" style="font-size:.68rem;"><?= h(substr($pr['npub_proposed'], 0, 22)) ?>…</div>
                            <?php else: ?>
                                <div class="mono" style="font-size:.68rem;"><?= h(substr($pr['npub_proposed'], 0, 26)) ?>…</div>
                                <div class="cell-muted">Pas encore de fiche</div>
                            <?php endif; ?>
                            <div style="margin-top:.2rem;">
                                <a href="https://njump.me/<?= h($pr['npub_proposed']) ?>" target="_blank"
                                   class="cell-muted" style="font-size:.72rem;text-decoration:underline;">
                                    Voir sur njump ↗
                                </a>
                            </div>
                        </div>
                    </div>
                </td>
                <td class="cell-muted mono" style="font-size:.72rem;">
                    <?php if ($pr['proposed_by']): ?>
                        <a href="/admin/profile-edit.php?npub=<?= urlencode($pr['proposed_by']) ?>"
                           style="color:var(--text-muted);">
                            <?= h(substr($pr['proposed_by'], 0, 16)) ?>…
                        </a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                    <?php if ($links): ?>
                        <div style="display:flex;flex-direction:column;gap:.2rem;">
                        <?php foreach ($links as $link): ?>
                            <div style="font-size:.78rem;">
                                <span><?= $platformIcons[$link['platform']] ?? '🔗' ?></span>
                                <a href="<?= h($link['url']) ?>" target="_blank" rel="noopener"
                                   style="color:var(--text-muted);text-decoration:underline;word-break:break-all;">
                                    <?= h(parse_url($link['url'], PHP_URL_HOST) . (parse_url($link['url'], PHP_URL_PATH) ?: '')) ?>
                                </a>
                            </div>
                        <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <span class="cell-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="max-width:180px;font-size:.82rem;"><?= h($pr['message'] ?: '—') ?></td>
                <td class="cell-muted" style="white-space:nowrap;"><?= date('d/m/y H:i', strtotime($pr['created_at'])) ?></td>
                <td>
                    <?php if ($pr['profile_status']): ?>
                        <span class="badge badge-<?= h($pr['profile_status']) ?>"><?= h($pr['profile_status']) ?></span>
                    <?php else: ?>
                        <span class="badge badge-pending">Pas de fiche</span>
                    <?php endif; ?>
                </td>
                <?php if ($statusFilter === 'pending'): ?>
                <td style="white-space:nowrap;">
                    <div class="actions">
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Accepter et créer la fiche ?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
                            <input type="hidden" name="action" value="accept">
                            <button type="submit" class="btn btn-success btn-sm">✅ Accepter</button>
                        </form>
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="id" value="<?= (int)$pr['id'] ?>">
                            <input type="hidden" name="action" value="reject">
                            <button type="submit" class="btn btn-danger btn-sm">❌ Rejeter</button>
                        </form>
                    </div>
                </td>
                <?php endif; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($proposals)): ?>
            <tr><td colspan="7">
                <div class="empty-state">
                    <div class="empty-state-icon">📬</div>
                    <p>Aucune proposition <?= $statusFilter === 'pending' ? 'en attente' : $statusFilter ?>.</p>
                </div>
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <span class="pagination-info">Page <?= $page ?> / <?= $totalPages ?></span>
        <?php for ($i = 1; $i <= $totalPages; $i++): ?>
            <a href="?status=<?= $statusFilter ?>&page=<?= $i ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
        <?php endfor; ?>
    </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
echo adminLayout('Propositions', $content);
