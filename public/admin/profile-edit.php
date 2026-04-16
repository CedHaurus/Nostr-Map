<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin();
$db    = getDB();

$npub         = trim($_GET['npub'] ?? '');
$autoRefresh  = isset($_GET['nostr_refresh']);
if (!$npub) redirect('/admin/profiles.php');

$profile = $db->prepare(
    'SELECT * FROM profiles WHERE npub = ?'
);
$profile->execute([$npub]);
$p = $profile->fetch();
if (!$p) {
    flash('Profil introuvable.', 'danger');
    redirect('/admin/profiles.php');
}

// ─── Traitement des actions POST ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? 'edit';

    // Bloquer toute modification d'un profil sanctuarisé pour les non-admins
    if ($p['protected'] && !isRole('admin')) {
        flash('Ce profil est sanctuarisé — modification impossible.', 'danger');
        redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
    }

    if ($action === 'add_link') {
        $platform = trim($_POST['platform'] ?? '');
        $url      = trim($_POST['url'] ?? '');
        $handle   = trim($_POST['display_handle'] ?? '');
        $verified = isset($_POST['verified']) ? 1 : 0;

        $allowed = ['x', 'mastodon', 'bluesky', 'youtube'];
        if (!in_array($platform, $allowed, true) || !$url) {
            flash('Plateforme ou URL invalide.', 'danger');
            redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
        }

        $challenge = bin2hex(random_bytes(16));
        $db->prepare(
            'INSERT INTO social_links (npub, platform, display_handle, url, challenge, verified, verified_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $npub,
            $platform,
            $handle ?: null,
            $url,
            $challenge,
            $verified,
            $verified ? date('Y-m-d H:i:s') : null,
        ]);

        logActivity('add_link', 'link', $platform, "Profil: {$npub}");
        flash('Lien ajouté.', 'success');
        redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
    }

    if ($action === 'edit') {
        $fields = ['cached_name','cached_avatar','cached_bio','cached_nip05','slug','status','banned_reason'];
        $set = []; $params = [];
        foreach ($fields as $f) {
            if (isset($_POST[$f])) {
                $val = trim($_POST[$f]);
                if ($f === 'slug') {
                    if (!preg_match('/^[a-z0-9_-]{2,50}$/i', $val)) {
                        flash('Slug invalide (2-50 chars, lettres/chiffres/-/_)', 'danger');
                        redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
                    }
                    // Vérifier unicité (sauf profil actuel)
                    $dup = $db->prepare('SELECT 1 FROM profiles WHERE slug=? AND npub!=?');
                    $dup->execute([$val, $npub]);
                    if ($dup->fetchColumn()) {
                        flash('Ce slug est déjà utilisé.', 'danger');
                        redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
                    }
                }
                $set[]    = "{$f} = ?";
                $params[] = $val ?: null;
            }
        }
        if ($set) {
            // Si l'admin change le cached_name, verrouiller pour protéger du cron
            if (isset($_POST['cached_name'])) {
                $set[]    = 'display_name_updated_at = NOW()';
            }
            $params[] = $npub;
            $db->prepare('UPDATE profiles SET ' . implode(', ', $set) . ' WHERE npub = ?')
               ->execute($params);
            logActivity('edit_profile', 'profile', $npub, 'Modification via admin');
            flash('Profil mis à jour.', 'success');
        }
        redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
    }

    if ($action === 'delete_link') {
        $linkId = (int)($_POST['link_id'] ?? 0);
        $db->prepare('DELETE FROM social_links WHERE id=? AND npub=?')->execute([$linkId, $npub]);
        logActivity('delete_link', 'link', (string)$linkId, "Profil: {$npub}");
        flash('Lien supprimé.', 'success');
        redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
    }

    if ($action === 'toggle_verify') {
        $linkId   = (int)($_POST['link_id'] ?? 0);
        $verified = (int)($_POST['verified'] ?? 0);
        $newVal   = $verified ? 0 : 1;
        $db->prepare('UPDATE social_links SET verified=?, verified_at=? WHERE id=? AND npub=?')
           ->execute([$newVal, $newVal ? date('Y-m-d H:i:s') : null, $linkId, $npub]);
        logActivity($newVal ? 'force_verify' : 'unverify', 'link', (string)$linkId);
        flash($newVal ? 'Lien marqué vérifié.' : 'Vérification retirée.', 'info');
        redirect('/admin/profile-edit.php?npub=' . urlencode($npub));
    }
}

// ─── Charger les liens ────────────────────────────────────────────────────
$links = $db->prepare(
    'SELECT * FROM social_links WHERE npub = ? ORDER BY verified DESC, id ASC'
);
$links->execute([$npub]);
$links = $links->fetchAll();

$csrf = csrfToken();

ob_start(); ?>

<!-- Retour + en-tête profil -->
<div style="margin-bottom:1rem;">
    <a href="/admin/profiles.php" class="btn btn-ghost btn-sm">← Retour aux profils</a>
    <a href="/p/<?= h($p['slug']) ?>" target="_blank" class="btn btn-ghost btn-sm">↗ Voir la page publique</a>
</div>

<div class="panel" style="margin-bottom:1.5rem;">
    <div class="profile-detail-header">
        <?php if ($p['cached_avatar']): ?>
            <img src="<?= h($p['cached_avatar']) ?>" class="avatar-lg" alt="" onerror="this.style.display='none'">
        <?php else: ?>
            <div class="avatar-placeholder-lg"><?= strtoupper(substr($p['cached_name'] ?: $p['slug'], 0, 1)) ?></div>
        <?php endif; ?>
        <div>
            <h2 style="font-size:1.2rem;font-weight:700;"><?= h($p['cached_name'] ?: '—') ?></h2>
            <div class="cell-muted">@<?= h($p['slug']) ?></div>
            <div style="margin-top:.4rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                <span class="badge badge-<?= h($p['status']) ?>"><?= h($p['status']) ?></span>
                <?php if ($p['protected']): ?>
                    <span class="badge" style="background:#f59e0b22;color:#f59e0b;border:1px solid #f59e0b55;">🛡️ Sanctuarisé</span>
                <?php endif; ?>
                <span class="text-muted text-sm">Inscrit le <?= date('d/m/Y', strtotime($p['registered_at'])) ?></span>
            </div>
            <div class="mono text-muted" style="margin-top:.4rem;font-size:.72rem;word-break:break-all;"><?= h($p['npub']) ?></div>
        </div>
    </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;align-items:start;">

<!-- Formulaire édition -->
<div class="panel">
    <div class="panel-header"><span class="panel-title">✏️ Modifier le profil</span></div>
    <div class="panel-body">
    <?php if ($p['protected']): ?>
        <div class="alert alert-warning" style="display:flex;align-items:center;gap:.5rem;">
            🛡️ <strong>Profil sanctuarisé.</strong> Modification impossible.
        </div>
    <?php else: ?>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit">

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nom affiché</label>
                    <input type="text" name="cached_name" class="form-control"
                           value="<?= h($p['cached_name']) ?>" maxlength="100">
                </div>
                <div class="form-group">
                    <label class="form-label">Slug (URL)</label>
                    <input type="text" name="slug" class="form-control"
                           value="<?= h($p['slug']) ?>" pattern="[a-zA-Z0-9_-]{2,50}" maxlength="50" required>
                    <div class="form-hint">nostrmap.fr/p/<strong><?= h($p['slug']) ?></strong></div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">URL Avatar</label>
                <input type="url" name="cached_avatar" class="form-control"
                       value="<?= h($p['cached_avatar']) ?>" maxlength="500">
            </div>

            <div class="form-group">
                <label class="form-label">Bio</label>
                <textarea name="cached_bio" class="form-control" maxlength="2000"><?= h($p['cached_bio']) ?></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">NIP-05</label>
                <input type="text" name="cached_nip05" class="form-control"
                       value="<?= h($p['cached_nip05']) ?>" maxlength="200"
                       placeholder="user@domain.fr">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Statut</label>
                    <select name="status" class="form-control">
                        <option value="active"  <?= $p['status']==='active'  ?'selected':'' ?>>Actif</option>
                        <option value="pending" <?= $p['status']==='pending' ?'selected':'' ?>>En attente</option>
                        <option value="banned"  <?= $p['status']==='banned'  ?'selected':'' ?>>Banni</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Raison bannissement</label>
                    <input type="text" name="banned_reason" class="form-control"
                           value="<?= h($p['banned_reason']) ?>" maxlength="255"
                           placeholder="Visible par l'équipe seulement">
                </div>
            </div>

            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;">
                💾 Sauvegarder
            </button>
        </form>

        <hr style="border-color:var(--border);margin:1.5rem 0;">
        <div>
            <p class="text-sm text-muted" style="margin-bottom:.75rem;">
                Récupère nom, avatar, bio, stats… depuis les relais Nostr et met à jour le cache.
            </p>
            <button type="button" id="btn-nostr-refresh" class="btn btn-info w-full" style="justify-content:center;"
                    onclick="startNostrRefresh()">
                🔄 Rafraîchir depuis Nostr
            </button>
        </div>

        <?php if (!$p['protected']): ?>
        <hr style="border-color:var(--border);margin:1.5rem 0;">
        <?php if (isRole('admin')): ?>
        <div>
            <p class="text-sm text-muted" style="margin-bottom:.75rem;">Chaque suppression est validée par un admin. Le profil est retiré temporairement de l'annuaire en attendant.</p>
            <button type="button" class="btn btn-danger w-full" style="justify-content:center;"
                onclick="if(confirm('Supprimer définitivement ce profil et tous ses liens ?')) {
                    document.getElementById('delete-profile-form').submit();
                }">
                🗑️ Supprimer ce profil définitivement
            </button>
            <form id="delete-profile-form" method="POST" action="/admin/profiles.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="npub" value="<?= h($npub) ?>">
            </form>
        </div>
        <?php else: ?>
        <div>
            <p class="text-sm text-muted" style="margin-bottom:.75rem;">Demander la suppression — l'admin validera ou restaurera.</p>
            <button type="button" class="btn btn-danger w-full" style="justify-content:center;"
                onclick="if(confirm('Envoyer une demande de suppression pour ce profil ?')) {
                    document.getElementById('reqdelete-profile-form').submit();
                }">
                🗑️ Demander la suppression
            </button>
            <form id="reqdelete-profile-form" method="POST" action="/admin/profiles.php">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="action" value="request_delete">
                <input type="hidden" name="npub" value="<?= h($npub) ?>">
            </form>
        </div>
        <?php endif; ?>
        <?php endif; /* !protected */ ?>
    <?php endif; /* !protected edit form */ ?>
    </div>
</div>

<!-- Liens RS -->
<div class="panel">
    <div class="panel-header">
        <span class="panel-title">🔗 Liens réseaux sociaux (<?= count($links) ?>)</span>
    </div>
    <?php if (empty($links)): ?>
        <div class="panel-body"><div class="empty-state"><p>Aucun lien RS.</p></div></div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead><tr>
                <th>Plateforme</th>
                <th>Handle / URL</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr></thead>
            <tbody>
            <?php foreach ($links as $l): ?>
            <tr>
                <td><strong><?= h($l['platform']) ?></strong></td>
                <td>
                    <div><?= h($l['display_handle'] ?: '—') ?></div>
                    <div class="cell-muted truncate" style="max-width:180px;">
                        <a href="<?= h($l['url']) ?>" target="_blank" rel="noopener"><?= h($l['url']) ?></a>
                    </div>
                    <div class="cell-muted" style="font-family:monospace;font-size:.72rem;">
                        Challenge : <?= h($l['challenge']) ?>
                    </div>
                </td>
                <td>
                    <?php if ($l['verified']): ?>
                        <span class="badge badge-verified">✓ Vérifié</span>
                        <?php if ($l['verified_at']): ?>
                            <div class="cell-muted"><?= date('d/m/Y', strtotime($l['verified_at'])) ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="badge badge-pending">Non vérifié</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!$p['protected'] || isRole('admin')): ?>
                    <div class="actions">
                        <!-- Toggle vérification -->
                        <form method="POST" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="toggle_verify">
                            <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
                            <input type="hidden" name="verified" value="<?= (int)$l['verified'] ?>">
                            <button type="submit" class="btn btn-sm <?= $l['verified'] ? 'btn-warning' : 'btn-success' ?>">
                                <?= $l['verified'] ? '✗ Désactiver' : '✓ Forcer vérif.' ?>
                            </button>
                        </form>
                        <!-- Supprimer lien -->
                        <form method="POST" style="display:inline;"
                              onsubmit="return confirm('Supprimer ce lien ?')">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_link">
                            <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm btn-icon">🗑️</button>
                        </form>
                    </div>
                    <?php else: ?>
                    <span class="text-muted" style="font-size:.8rem;">🛡️ Protégé</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php if (!$p['protected'] || isRole('admin')): ?>
    <div style="border-top:1px solid var(--border);padding:1rem 1.25rem;">
        <div style="font-weight:600;margin-bottom:.75rem;font-size:.9rem;">➕ Ajouter un lien</div>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="add_link">
            <div class="form-row" style="margin-bottom:.6rem;">
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Plateforme</label>
                    <select name="platform" class="form-control" required>
                        <option value="">— choisir —</option>
                        <option value="x">X / Twitter</option>
                        <option value="mastodon">Mastodon</option>
                        <option value="bluesky">Bluesky</option>
                        <option value="youtube">YouTube</option>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label class="form-label">Handle affiché <span class="text-muted">(optionnel)</span></label>
                    <input type="text" name="display_handle" class="form-control" maxlength="100" placeholder="@pseudo">
                </div>
            </div>
            <div class="form-group" style="margin-bottom:.6rem;">
                <label class="form-label">URL</label>
                <input type="url" name="url" class="form-control" maxlength="500" placeholder="https://…" required>
            </div>
            <div style="display:flex;align-items:center;gap:1rem;">
                <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;cursor:pointer;">
                    <input type="checkbox" name="verified" value="1"> Marquer comme vérifié
                </label>
                <button type="submit" class="btn btn-primary btn-sm" style="margin-left:auto;">
                    ➕ Ajouter
                </button>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

</div><!-- /grid -->

<?php
$content = ob_get_clean();

// ── Overlay refresh Nostr ──────────────────────────────────────────────────
$content .= '
<div id="nostr-refresh-overlay" style="display:none;position:fixed;inset:0;z-index:9999;
     background:rgba(13,13,26,.85);backdrop-filter:blur(6px);
     align-items:center;justify-content:center;flex-direction:column;gap:1.5rem;">
  <div style="position:relative;width:56px;height:56px;">
    <span class="spinner" style="position:absolute;inset:0;width:56px;height:56px;border-width:3px;opacity:.3;"></span>
    <span class="spinner" style="position:absolute;inset:8px;width:40px;height:40px;border-width:2px;animation-duration:.7s;"></span>
  </div>
  <div style="text-align:center;">
    <div style="font-size:1rem;font-weight:600;color:#fff;" id="refresh-label">Connexion aux relais Nostr…</div>
    <div style="font-size:.8rem;color:rgba(255,255,255,.5);margin-top:.4rem;">Récupération du profil en cours</div>
  </div>
</div>';

// ── Script module Nostr ────────────────────────────────────────────────────
$content .= '<script type="module">
import { nostr } from "/assets/js/nostr.js";

const _npub  = ' . json_encode($p['npub']) . ';
const _csrf  = ' . json_encode(csrfToken()) . ';
const _autoR = ' . json_encode($autoRefresh) . ';

function npubToHex(npub) {
  const C = "qpzry9x8gf2tvdw0s3jn54khce6mua7l";
  const s = npub.toLowerCase(), sep = s.lastIndexOf("1");
  if (sep < 1) throw new Error("bad npub");
  let acc = 0, bits = 0; const bytes = [];
  for (const c of s.slice(sep + 1, -6)) {
    const i = C.indexOf(c); if (i < 0) throw new Error("bad char");
    acc = (acc << 5) | i; bits += 5;
    while (bits >= 8) { bits -= 8; bytes.push((acc >> bits) & 0xff); }
  }
  return bytes.map(b => b.toString(16).padStart(2,"0")).join("");
}

function setLabel(msg) {
  const el = document.getElementById("refresh-label");
  if (el) el.textContent = msg;
}

window.startNostrRefresh = async function() {
  const overlay = document.getElementById("nostr-refresh-overlay");
  overlay.style.display = "flex";
  const btn = document.getElementById("btn-nostr-refresh");
  if (btn) btn.disabled = true;

  const data = { npub: _npub, csrf_token: _csrf };
  let pubHex;
  try { pubHex = npubToHex(_npub); } catch {}

  setLabel("Connexion aux relais Nostr…");

  await new Promise(resolve => {
    let profileDone = false;

    // Timeout global 10s
    const gTimeout = setTimeout(() => { profileDone = true; resolve(); }, 10000);

    nostr.fetchProfile(_npub, (live) => {
      if (profileDone) return;

      if (!live) {
        // Relay timeout avec null = plus rien à attendre
        clearTimeout(gTimeout);
        profileDone = true;
        resolve();
        return;
      }

      setLabel("Profil trouvé — récupération des stats…");
      if (live.name || live.display_name) data.cached_name   = live.name || live.display_name;
      if (live.picture)                   data.cached_avatar = live.picture;
      if (live.about)                     data.cached_bio    = live.about;
      if (live.nip05)                     data.cached_nip05  = live.nip05;

      if (pubHex && !profileDone) {
        profileDone = true;
        clearTimeout(gTimeout); // éviter race condition : gTimeout ne doit pas résoudre avant fetchStats
        nostr.fetchStats(pubHex, null, (stats) => {
          if (stats.followers != null)  data.nostr_followers  = stats.followers;
          if (stats.posts != null)      data.nostr_posts      = stats.posts;
          if (stats.createdAt)          data.nostr_created_at = stats.createdAt;
          resolve();
        });
      }
    });
  });

  setLabel("Enregistrement…");

  try {
    await fetch("/admin/nostr-refresh.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
  } catch (e) { console.error("[nostr-refresh]", e); }

  // Recharger sans le param nostr_refresh
  const url = new URL(window.location.href);
  url.searchParams.delete("nostr_refresh");
  url.searchParams.set("refreshed", "1");
  window.location.href = url.toString();
};

// Auto-refresh si redirigé depuis profile-create
if (_autoR) {
  window.startNostrRefresh();
}
</script>';

echo adminLayout('Modifier le profil : @' . h($p['slug']), $content);
