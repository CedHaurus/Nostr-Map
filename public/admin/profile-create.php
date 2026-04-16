<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth.php';
$admin = requireAdmin(); // modo+
$db    = getDB();

$errors = [];
$values = ['npub' => '', 'cached_name' => '', 'cached_nip05' => '', 'cached_bio' => '', 'cached_avatar' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $npub        = trim($_POST['npub'] ?? '');
    $cachedName  = trim($_POST['cached_name'] ?? '');
    $cachedNip05 = trim($_POST['cached_nip05'] ?? '');
    $cachedBio   = trim($_POST['cached_bio'] ?? '');
    $cachedAvatar= trim($_POST['cached_avatar'] ?? '');

    $values = compact('npub', 'cached_name', 'cached_nip05', 'cached_bio', 'cached_avatar');
    $values['cached_name']   = $cachedName;
    $values['cached_nip05']  = $cachedNip05;
    $values['cached_bio']    = $cachedBio;
    $values['cached_avatar'] = $cachedAvatar;

    // Validation npub
    if (!$npub) {
        $errors[] = 'La clé publique npub est obligatoire.';
    } elseif (!isValidNpub($npub)) {
        $errors[] = 'Format npub invalide (doit commencer par npub1, minimum 60 caractères, bech32).';
    } else {
        // Vérifier unicité
        $dup = $db->prepare('SELECT 1 FROM profiles WHERE npub = ?');
        $dup->execute([$npub]);
        if ($dup->fetchColumn()) {
            $errors[] = 'Ce npub est déjà enregistré. <a href="/admin/profiles.php?q=' . urlencode($npub) . '">Voir le profil existant →</a>';
        }
    }

    if (empty($errors)) {
        $slug = generateAdminSlug($npub, $cachedNip05 ?: null, $db);

        $db->prepare(
            'INSERT INTO profiles
             (npub, slug, cached_name, cached_nip05, cached_bio, cached_avatar, status, registered_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())'
        )->execute([
            $npub,
            $slug,
            $cachedName   ?: null,
            $cachedNip05  ?: null,
            $cachedBio    ?: null,
            $cachedAvatar ?: null,
            'active',
        ]);

        logActivity('create_profile', 'profile', $npub, "slug: {$slug}");
        flash("Fiche @{$slug} créée. Récupération des données Nostr en cours…", 'info');
        redirect('/admin/profile-edit.php?npub=' . urlencode($npub) . '&nostr_refresh=1');
    }
}

$csrf = csrfToken();
ob_start(); ?>

<div style="max-width:680px;margin:0 auto;">

<div style="margin-bottom:1rem;">
    <a href="/admin/profiles.php" class="btn btn-ghost btn-sm">← Retour aux profils</a>
</div>

<div class="panel">
    <div class="panel-header">
        <span class="panel-title">➕ Créer une fiche à partir d'un npub</span>
    </div>
    <div class="panel-body">

        <?php if ($errors): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $e): ?>
                <div><?= $e ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="alert alert-info" style="margin-bottom:1.5rem;">
            <strong>Comment ça fonctionne :</strong><br>
            Renseignez le npub Nostr de l'utilisateur. Une fiche sera créée avec statut <em>actif</em>.
            L'utilisateur pourra ensuite se connecter via son extension Nostr (NIP-07) et modifier sa fiche.
            Les données Nostr (avatar, bio…) seront chargées automatiquement à sa première connexion.
        </div>

        <form method="POST" autocomplete="off">
            <?= csrfField() ?>

            <div class="form-group">
                <label class="form-label">Clé publique npub <span style="color:var(--danger)">*</span></label>
                <input type="text" name="npub" class="form-control mono"
                       value="<?= h($values['npub']) ?>"
                       placeholder="npub1…"
                       pattern="npub1[qpzry9x8gf2tvdw0s3jn54khce6mua7lQPZRY9X8GF2TVDW0S3JN54KHCE6MUA7L]{58,}"
                       maxlength="120" required
                       style="font-family:monospace;font-size:.85rem;">
                <div class="form-hint">Format bech32 commençant par <code>npub1</code></div>
            </div>

            <hr style="border-color:var(--border);margin:1.25rem 0;">
            <p class="text-sm text-muted" style="margin-bottom:1rem;">
                Champs optionnels — ils seront écrasés par les données Nostr lors de la première connexion de l'utilisateur.
            </p>

            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">Nom affiché</label>
                    <input type="text" name="cached_name" class="form-control"
                           value="<?= h($values['cached_name']) ?>" maxlength="100"
                           placeholder="ex: Alice Dupont">
                </div>
                <div class="form-group">
                    <label class="form-label">NIP-05</label>
                    <input type="text" name="cached_nip05" class="form-control"
                           value="<?= h($values['cached_nip05']) ?>" maxlength="200"
                           placeholder="alice@domain.fr">
                    <div class="form-hint">Influence le slug généré automatiquement</div>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">URL Avatar</label>
                <input type="url" name="cached_avatar" class="form-control"
                       value="<?= h($values['cached_avatar']) ?>" maxlength="500"
                       placeholder="https://…">
            </div>

            <div class="form-group">
                <label class="form-label">Bio</label>
                <textarea name="cached_bio" class="form-control" maxlength="2000"
                          rows="3" placeholder="Courte biographie…"><?= h($values['cached_bio']) ?></textarea>
            </div>

            <button type="submit" class="btn btn-primary w-full" style="justify-content:center;margin-top:.5rem;">
                ➕ Créer la fiche
            </button>
        </form>
    </div>
</div>

</div>

<?php
$content = ob_get_clean();
echo adminLayout('Créer une fiche', $content);
