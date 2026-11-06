  import { auth }       from '/assets/js/auth.js?v=20260423-modal';
  import { app }        from '/assets/js/app.js?v=20261106-csp';
  import { nostr }      from '/assets/js/nostr.js';
  import { initVerify } from '/assets/js/verify.js?v=20261106-csp';

  const token    = auth.getToken();
  const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent)
                || (navigator.maxTouchPoints > 1 && /Macintosh/i.test(navigator.userAgent));
  let dashboardStarted = false;

  // Initialiser le header standard (chip utilisateur + déconnexion)
  auth.initUI({
    loginBtn:  document.getElementById('btn-login-header'),
    logoutBtn: document.getElementById('btn-logout'),
    userMenu:  document.getElementById('user-menu'),
    slugEl:    document.getElementById('user-slug-display'),
    onLogin:   showDashboard,
  });

  if (!token) {
    document.getElementById('auth-gate').classList.remove('hidden');
    document.getElementById('auth-gate-desc').textContent =
      'Connectez-vous pour accéder à votre dashboard.';
    document.getElementById('btn-login-gate').addEventListener('click', async () => {
      const ok = await auth.login();
      if (ok) showDashboard();
    });
  } else {
    showDashboard();
  }

  function showDashboard() {
    if (dashboardStarted) return;
    dashboardStarted = true;
    document.getElementById('auth-gate').classList.add('hidden');
    initDashboard();
  }

  async function initDashboard() {
    const decoded = auth.getDecoded();
    if (!decoded) { auth.logout(); location.href = '/'; return; }

    document.getElementById('dashboard').classList.remove('hidden');
    let { npub, slug } = decoded;

    // ── Fonction pour rafraîchir les éléments dépendant du slug ────────────────
    function applySlug(s) {
      slug = s;
      document.getElementById('dash-slug-title').textContent = '@' + s; // mis à jour après loadPreview
      const u = `https://nostrmap.fr/p/${s}`;
      document.getElementById('public-url-text').textContent = u;
    }

    // Titre + URL publique (slug actuel du JWT)
    applySlug(slug);
    document.getElementById('btn-copy-url').addEventListener('click', () => {
      navigator.clipboard.writeText(`https://nostrmap.fr/p/${slug}`)
        .then(() => app.toast('URL copiée !', 'success'));
    });

    // ── 1. Charger l'aperçu immédiatement depuis la DB ──────────────────────────
    await loadPreview(slug);

    // ── 2. Charger les liens ────────────────────────────────────────────────────
    await loadLinks(npub, slug);

    // ── 3. QR Code, vérification, ajout lien ──────────────────────────────────
    initDashQR(slug, npub);
    initVerify({ npub, slug, onUpdate: () => loadLinks(npub, slug) });
    initAddLinkFlow(npub, slug);
    initDeleteProfile(npub);

    // ── 4. Overlay chargement + fetch relais ───────────────────────────────────
    const overlay = document.getElementById('loading-overlay');
    const loadingLabel = document.getElementById('loading-label');
    overlay.classList.remove('hidden');
    overlay.style.opacity = '1';

    // Convertir npub → hex pour fetchStats
    let pubkeyHex = null;
    try { pubkeyHex = _npubToHex(npub); } catch {}

    nostr.fetchProfile(npub, async (profile) => {
      if (!profile) {
        // Aucun relay n'a répondu → masquer overlay silencieusement
        _hideOverlay(overlay);
        return;
      }

      loadingLabel.textContent = 'Récupération des statistiques…';

      // Fetch stats (followers, posts) si on a la clé hex
      let statsPayload = {};
      if (pubkeyHex) {
        await new Promise(resolve => {
          nostr.fetchStats(pubkeyHex, null, (stats) => {
            statsPayload = {
              nostr_created_at: stats.createdAt  || 0,
              nostr_followers:  stats.followers  || 0,
              nostr_posts:      stats.posts      || 0,
            };
            resolve();
          });
        });
      }

      loadingLabel.textContent = 'Mise à jour du profil…';

      try {
        const res = await fetch('/api/profile.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + auth.getToken(),
          },
          body: JSON.stringify({
            cached_name:   profile.display_name || profile.name || '',
            cached_avatar: profile.picture || '',
            cached_bio:    profile.about || '',
            cached_nip05:  profile.nip05 || '',
            ...statsPayload,
          }),
        });

        const data = await res.json();

        // Nouveau slug (NIP-05 vient d'être enregistré) → mettre à jour le token
        if (data.newToken && data.newSlug) {
          auth.setToken(data.newToken);
          applySlug(data.newSlug);
          app.toast('Slug mis à jour : @' + data.newSlug, 'success');
        }
      } catch {}

      // Masquer l'overlay et recharger l'aperçu avec les données fraîches
      _hideOverlay(overlay);
      await loadPreview(slug);
    });
  }

  function _hideOverlay(overlay) {
    overlay.style.opacity = '0';
    setTimeout(() => overlay.classList.add('hidden'), 400);
  }

  // Copie locale de npubToHex pour éviter un import supplémentaire
  function _npubToHex(npub) {
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    const str = npub.toLowerCase();
    const sep = str.lastIndexOf('1');
    if (sep < 1) throw new Error('Invalid npub');
    const dataStr = str.slice(sep + 1, -6);
    const data5   = Array.from(dataStr).map(c => {
      const idx = CHARSET.indexOf(c);
      if (idx < 0) throw new Error('Invalid char');
      return idx;
    });
    let acc = 0, bits = 0;
    const bytes = [];
    for (const v of data5) {
      acc = (acc << 5) | v; bits += 5;
      while (bits >= 8) { bits -= 8; bytes.push((acc >> bits) & 0xff); }
    }
    return bytes.map(b => b.toString(16).padStart(2, '0')).join('');
  }

  async function loadPreview(slug) {
    try {
      const res  = await fetch(`/api/profile.php?slug=${slug}`, {
        headers: { 'Authorization': 'Bearer ' + auth.getToken() }
      });
      const data = await res.json();
      if (data.profile) {
        document.getElementById('preview-card-wrap').innerHTML =
          app.renderCard(data.profile, data.links || [], { compact: true });
        initDisplayNameEditor(data.profile);
        // Afficher le nom d'affichage dans le titre (pas le handle)
        const displayName = data.profile.cached_name;
        if (displayName) {
          document.getElementById('dash-slug-title').textContent = displayName;
        }
      }
    } catch (e) { /* silencieux */ }
  }

  function initDisplayNameEditor(profile) {
    const input   = document.getElementById('display-name-input');
    const btn     = document.getElementById('btn-save-display-name');
    const hint    = document.getElementById('display-name-hint');

    input.value = profile.cached_name || '';

    // Afficher le cooldown si applicable
    if (profile.display_name_updated_at) {
      const updatedAt  = new Date(profile.display_name_updated_at.replace(' ', 'T') + 'Z');
      const nextChange = new Date(updatedAt.getTime() + 48 * 60 * 60 * 1000);
      const now        = new Date();
      if (nextChange > now) {
        const remaining = nextChange - now;
        const h = Math.floor(remaining / 3600000);
        const m = Math.floor((remaining % 3600000) / 60000);
        input.disabled = true;
        btn.disabled   = true;
        hint.textContent = `Prochain changement possible dans ${h}h${String(m).padStart(2,'0')}m`;
        // Débloquer automatiquement quand le cooldown expire
        setTimeout(() => {
          input.disabled = false;
          btn.disabled   = false;
          hint.textContent = 'Vous pouvez modifier votre nom d\'affichage.';
        }, remaining);
        return;
      }
    }
    hint.textContent = 'Une fois modifié, vous devrez attendre 48h avant de changer à nouveau.';

    btn.onclick = async () => {
      const name = input.value.trim();
      if (!name) { app.toast('Le nom ne peut pas être vide', 'warning'); return; }
      btn.disabled = true;
      btn.textContent = '…';
      try {
        const res  = await fetch('/api/profile.php?action=set_display_name', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Authorization': 'Bearer ' + auth.getToken(),
          },
          body: JSON.stringify({ name }),
        });
        const data = await res.json();
        if (data.success) {
          app.toast('Nom mis à jour !', 'success');
          input.disabled = true;
          btn.disabled   = true;
          hint.textContent = 'Prochain changement possible dans 48h00m';
        } else {
          app.toast(data.error || 'Erreur', 'danger');
          btn.disabled = false;
        }
      } catch {
        app.toast('Erreur réseau', 'danger');
        btn.disabled = false;
      }
      btn.textContent = 'Sauvegarder';
      // Recharger l'aperçu pour refléter le nouveau nom
      await loadPreview(profile.slug);
    };
  }

  async function loadLinks(npub, slug) {
    try {
      const res  = await fetch(`/api/profile.php?slug=${slug}`, {
        headers: { 'Authorization': 'Bearer ' + auth.getToken() }
      });
      const data = await res.json();
      renderLinks(data.links || [], npub, slug);
    } catch (e) {
      document.getElementById('links-list').innerHTML =
        '<p class="text-muted" style="font-size:.85rem;">Erreur de chargement.</p>';
    }
  }

  // Échappe les caractères HTML dangereux pour éviter les injections XSS
  function esc(s) {
    return String(s ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function renderLinks(links, npub, slug) {
    const container = document.getElementById('links-list');

    if (!links.length) {
      container.innerHTML = `
        <div class="empty-state" style="padding:1.5rem 0;">
          <div class="empty-state-icon">🔗</div>
          <p>Aucun réseau social ajouté.</p>
        </div>`;
      return;
    }

    container.innerHTML = links.map(link => `
      <div class="link-row" id="link-row-${esc(link.id)}">
        <span class="link-platform-icon platform-${esc(link.platform)}">
          ${app.platformIcon(link.platform)}
        </span>
        <div class="link-row-info">
          <div style="font-weight:600;font-size:.88rem;">
            ${esc(link.display_handle) || esc(app.platformName(link.platform))}
          </div>
          <div class="link-row-url">${esc(link.url)}</div>
          ${!link.verified ? `
          <div class="challenge-box" id="challenge-${esc(link.id)}">
            <div class="challenge-label">
              <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              <span>Code challenge</span>
            </div>
            <p class="challenge-hint">A coller le temps de vérifier</p>
            <div class="challenge-code-row">
              <code class="challenge-code">${esc(link.challenge)}</code>
              <button class="btn btn-ghost btn-sm challenge-copy-btn" title="Copier" data-copy="${esc(link.challenge)}">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                  <rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg>
              </button>
            </div>
          </div>` : ''}
        </div>
        <div class="link-row-actions">
          ${link.verified
            ? `<span class="badge badge-verified">✓ Vérifié</span>`
            : `<button class="btn btn-outline btn-sm" data-verify="${esc(link.id)}">Vérifier</button>`
          }
          <button class="btn btn-danger btn-sm" data-delete="${esc(link.id)}" title="Supprimer">✕</button>
        </div>
      </div>
    `).join('');

    // Boutons copier le challenge
    container.querySelectorAll('[data-copy]').forEach(btn => {
      btn.addEventListener('click', () => {
        navigator.clipboard.writeText(btn.dataset.copy)
          .then(() => app.toast('Code copié !', 'success'));
      });
    });

    // Boutons vérifier
    container.querySelectorAll('[data-verify]').forEach(btn => {
      btn.addEventListener('click', async () => {
        const id = parseInt(btn.dataset.verify);
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span>';
        await window._verifyLink(id);
        await loadLinks(npub, slug);
      });
    });

    // Boutons supprimer
    container.querySelectorAll('[data-delete]').forEach(btn => {
      btn.addEventListener('click', async () => {
        if (!confirm('Supprimer ce lien ?')) return;
        const id = parseInt(btn.dataset.delete);
        try {
          await fetch(`/api/profile.php?action=delete_link&id=${id}`, {
            method: 'DELETE',
            headers: { 'Authorization': 'Bearer ' + auth.getToken() },
          });
          await loadLinks(npub, slug);
          app.toast('Lien supprimé.', 'info');
        } catch(e) {
          app.toast('Erreur lors de la suppression.', 'error');
        }
      });
    });
  }

  function initAddLinkFlow(npub, slug) {
    let pendingLinkId = null;

    const step1    = document.getElementById('add-step-1');
    const step2    = document.getElementById('add-step-2');
    const btnGet   = document.getElementById('btn-get-challenge');
    const btnConfirm = document.getElementById('btn-confirm-link');
    const btnCancel  = document.getElementById('btn-cancel-step2');
    const btnCopy    = document.getElementById('btn-copy-challenge');

    // Étape 1 → générer le code
    btnGet.addEventListener('click', async () => {
      const platform = document.getElementById('link-platform').value;
      const handle   = document.getElementById('link-handle').value.trim();

      btnGet.disabled = true;
      btnGet.innerHTML = '<span class="spinner"></span>';

      try {
        const res  = await fetch('/api/profile.php?action=generate_challenge', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + auth.getToken() },
          body: JSON.stringify({ platform, display_handle: handle }),
        });
        const data = await res.json();
        if (data.error) { app.toast(data.error, 'error'); return; }

        pendingLinkId = data.link_id;
        document.getElementById('new-challenge-code').textContent = data.challenge;

        // Adapter les instructions selon la plateforme
        const placeholders = {
          x:        ['Ton code challenge — poste-le le temps de vérifier',
                     'Colle ici l\'URL du tweet contenant le code :',
                     'https://x.com/toi/status/123456789…'],
          bluesky:  ['Ton code challenge — colle-le temporairement dans ta bio Bluesky',
                     'Entre l\'URL de ton profil Bluesky :',
                     'https://bsky.app/profile/toi.bsky.social'],
          mastodon: ['Ton code challenge — colle-le temporairement dans ta bio Mastodon',
                     'Entre l\'URL de ton profil Mastodon (n\'importe quelle instance publique) :',
                     'https://mastodon.online/@toi'],
          youtube:  ['Ton code challenge — colle-le temporairement dans la description de ta chaîne',
                     'Entre l\'URL de ta chaîne YouTube :',
                     'https://youtube.com/@ta-chaine'],
        };
        const [labelText, urlLabel, urlPlaceholder] = placeholders[platform] || placeholders.mastodon;
        document.getElementById('challenge-label-text').textContent = labelText;
        document.getElementById('link-url-label').textContent = urlLabel;
        document.getElementById('link-url').placeholder = urlPlaceholder;

        step1.classList.add('hidden');
        step2.classList.remove('hidden');
      } catch(e) {
        app.toast('Erreur réseau.', 'error');
      } finally {
        btnGet.disabled = false;
        btnGet.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Obtenir mon code challenge`;
      }
    });

    // Copier le code
    btnCopy.addEventListener('click', () => {
      const code = document.getElementById('new-challenge-code').textContent;
      navigator.clipboard.writeText(code).then(() => app.toast('Code copié !', 'success'));
    });

    // Annuler étape 2
    btnCancel.addEventListener('click', () => {
      step2.classList.add('hidden');
      step1.classList.remove('hidden');
      document.getElementById('link-url').value = '';
      pendingLinkId = null;
      loadLinks(npub, slug);
    });

    // Étape 2 → enregistrer URL + vérifier
    btnConfirm.addEventListener('click', async () => {
      const url = document.getElementById('link-url').value.trim();
      if (!url) { app.toast('Entre l\'URL de ton profil.', 'error'); return; }
      if (!pendingLinkId) return;

      btnConfirm.disabled = true;
      btnConfirm.innerHTML = '<span class="spinner"></span> Vérification…';

      try {
        // Enregistrer l'URL
        const setRes = await fetch('/api/profile.php?action=set_url', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + auth.getToken() },
          body: JSON.stringify({ link_id: pendingLinkId, url }),
        });
        const setData = await setRes.json();
        if (setData.error) { app.toast(setData.error, 'error'); return; }

        // Lancer la vérification
        await window._verifyLink(pendingLinkId);

        // Reset
        step2.classList.add('hidden');
        step1.classList.remove('hidden');
        document.getElementById('link-url').value    = '';
        document.getElementById('link-handle').value = '';
        pendingLinkId = null;
        await loadLinks(npub, slug);
      } catch(e) {
        app.toast('Erreur réseau.', 'error');
      } finally {
        btnConfirm.disabled = false;
        btnConfirm.innerHTML = `<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg> Confirmer et vérifier`;
      }
    });
  }

  function initDashQR(slug, npub) {
    const { QRCode: qr } = window;
    if (!qr) return;

    const canvas = document.getElementById('dash-qr-canvas');
    let mode = 'url';
    let qrInstance = null;

    function makeQR() {
      canvas.innerHTML = '';
      const content = mode === 'url'
        ? `https://nostrmap.fr/p/${slug}`
        : `nostr:${npub}`;
      qrInstance = new qr(canvas, {
        text: content, width: 200, height: 200,
        colorDark: '#ffffff', colorLight: '#13131a',
        correctLevel: qr.CorrectLevel.M,
      });
    }
    makeQR();

    document.getElementById('dash-qr-mode-url').addEventListener('click', function() {
      mode = 'url';
      this.classList.add('active');
      document.getElementById('dash-qr-mode-nostr').classList.remove('active');
      makeQR();
    });
    document.getElementById('dash-qr-mode-nostr').addEventListener('click', function() {
      mode = 'nostr';
      this.classList.add('active');
      document.getElementById('dash-qr-mode-url').classList.remove('active');
      makeQR();
    });
    document.getElementById('dash-qr-download').addEventListener('click', () => {
      const cvs = canvas.querySelector('canvas');
      if (!cvs) return;
      const link = document.createElement('a');
      link.download = `nostrmap-${slug}-qr.png`;
      link.href = cvs.toDataURL('image/png');
      link.click();
    });
  }

  // ── Suppression de profil ────────────────────────────────────────────────────
  function initDeleteProfile(npub) {
    const modal      = document.getElementById('modal-delete-profile');
    const btnOpen    = document.getElementById('btn-delete-profile');
    const btnClose   = document.getElementById('btn-delete-modal-close');
    const btnCancel  = document.getElementById('btn-delete-cancel');
    const btnConfirm = document.getElementById('btn-delete-confirm');
    const btnText    = document.getElementById('btn-delete-confirm-text');
    const btnSpin    = document.getElementById('btn-delete-confirm-spin');

    btnOpen?.addEventListener('click', () => modal.classList.remove('hidden'));
    btnClose?.addEventListener('click', () => modal.classList.add('hidden'));
    btnCancel?.addEventListener('click', () => modal.classList.add('hidden'));
    modal?.addEventListener('click', e => { if (e.target === modal) modal.classList.add('hidden'); });

    btnConfirm?.addEventListener('click', async () => {
      btnConfirm.disabled = true;
      btnText.classList.add('hidden');
      btnSpin.classList.remove('hidden');

      try {
        const res  = await fetch('/api/profile.php?action=request_deletion', {
          method:  'DELETE',
          headers: { 'Authorization': 'Bearer ' + auth.getToken() },
        });
        const data = await res.json();

        if (data.success) {
          modal.classList.add('hidden');
          app.toast('Profil supprimé.', 'success');
          setTimeout(() => {
            auth.logout();
            location.href = '/';
          }, 1500);
        } else {
          app.toast(data.error || 'Erreur lors de la demande.', 'danger');
          btnConfirm.disabled = false;
          btnText.classList.remove('hidden');
          btnSpin.classList.add('hidden');
        }
      } catch {
        app.toast('Erreur réseau.', 'danger');
        btnConfirm.disabled = false;
        btnText.classList.remove('hidden');
        btnSpin.classList.add('hidden');
      }
    });
  }
