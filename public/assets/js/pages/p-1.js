  import { app }   from '/assets/js/app.js?v=20261106-csp';
  import { nostr } from '/assets/js/nostr.js';
  import { auth }  from '/assets/js/auth.js?v=20260423-modal';

  // npub → hex (pour fetchStats)
  function npubToHex(npub) {
    const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
    const str = npub.toLowerCase(), sep = str.lastIndexOf('1');
    if (sep < 1) throw new Error('Invalid npub');
    const data5 = Array.from(str.slice(sep + 1, -6)).map(c => {
      const i = CHARSET.indexOf(c); if (i < 0) throw new Error('Bad char'); return i;
    });
    let acc = 0, bits = 0; const bytes = [];
    for (const v of data5) { acc = (acc << 5) | v; bits += 5; while (bits >= 8) { bits -= 8; bytes.push((acc >> bits) & 0xff); } }
    return bytes.map(b => b.toString(16).padStart(2,'0')).join('');
  }

  // Auth header
  auth.initUI({
    loginBtn:  document.getElementById('btn-login'),
    logoutBtn: document.getElementById('btn-logout'),
    userMenu:  document.getElementById('user-menu'),
    slugEl:    document.getElementById('user-slug-display'),
  });

  // Bouton retour : history.back() si on vient du site, sinon "/" direct.
  // Déclenche le mécanisme de restauration de position dans index.html
  // (sessionStorage nm_nav lu au retour par tryRestoreNavState).
  document.getElementById('profile-back-btn').addEventListener('click', () => {
    try {
      if (document.referrer && new URL(document.referrer).origin === location.origin) {
        history.back();
        return;
      }
    } catch {}
    location.href = '/';
  });

  // Récupérer le slug depuis l'URL (?slug=... via réécriture Nginx)
  const params = new URLSearchParams(window.location.search);
  const slug   = params.get('slug') || location.pathname.replace(/^\/p\//, '').replace(/\/$/, '');

  if (!slug) {
    showNotFound();
  } else {
    loadProfile(slug);
  }

  function setLoaderLabel(msg) {
    const el = document.getElementById('loader-label');
    if (el) el.textContent = msg;
  }

  async function loadProfile(slug) {
    let p, links;

    // ── 1. Données pré-chargées par p.php (SSR) — aucun aller-retour réseau ──
    const ssrEl = document.getElementById('profile-data');
    if (ssrEl) {
      try {
        const data = JSON.parse(ssrEl.textContent);
        if (data.profile) {
          p     = data.profile;
          links = data.links || [];
        }
      } catch(e) {
        console.error('[SSR parse error]', e);
      }
    }

    // ── 2. Fallback : fetch API (p.html servi directement, sans p.php) ───────
    if (!p) {
      try {
        setLoaderLabel('Connexion aux relais Nostr…');
        const res  = await fetch(`/api/profile.php?slug=${encodeURIComponent(slug)}`);
        if (res.status === 404) { showNotFound(); return; }
        const data = await res.json();
        if (!data.profile) { showNotFound(); return; }
        p     = data.profile;
        links = data.links || [];
      } catch (e) {
        console.error('[loadProfile] fetch error:', e);
        showNotFound();
        return;
      }
    }

    // ── 3. Rendu immédiat ────────────────────────────────────────────────────
    // Priorité : localStorage (< 1h) > DB cache
    let pubHex;
    try { pubHex = npubToHex(p.npub); } catch {}

    const cachedStats = app.getCachedStats(p.npub);
    if (cachedStats) {
      p = { ...p,
        nostr_followers:  cachedStats.followers  ?? p.nostr_followers,
        nostr_posts:      cachedStats.posts       ?? p.nostr_posts,
        nostr_created_at: cachedStats.createdAt   ?? p.nostr_created_at,
      };
    }
    try { renderProfile(p, links); } catch(e) { console.error('[renderProfile]', e); }

    // ── 4. Stats fraîches en arrière-plan (un seul appel) ────────────────────
    if (pubHex) {
      nostr.fetchStats(pubHex, p.nostr_created_at || null, (stats) => {
        try {
          app.setCachedStats(p.npub, {
            followers:  stats.followers,
            posts:      stats.posts,
            createdAt:  stats.createdAt || p.nostr_created_at,
          });
          renderStats({
            nostr_created_at: stats.createdAt  || p.nostr_created_at,
            nostr_followers:  stats.followers  != null ? stats.followers : p.nostr_followers,
            nostr_posts:      stats.posts      != null ? stats.posts     : p.nostr_posts,
          });
          // Les statistiques en base sont tenues par le serveur (tâche horaire) :
          // un visiteur ne les écrit plus, il ne fait que les afficher.
        } catch(e) { console.error('[renderStats]', e); }
      });
    }

    // ── 5. Mise à jour live nom/avatar/bio depuis les relais ─────────────────
    nostr.fetchProfile(p.npub, (live) => {
      if (!live) return;
      if (live.display_name || live.name) {
        const liveName = live.display_name || live.name;
        document.getElementById('ph-name').textContent = liveName;
        document.title = `${liveName} — Nostr Map`;
      }
      if (live.picture) {
        const img = document.getElementById('ph-avatar-img');
        const ph  = document.getElementById('ph-avatar-placeholder');
        if (img) {
          img.src = live.picture;
        } else if (ph) {
          const newImg = document.createElement('img');
          newImg.src = live.picture; newImg.className = 'profile-hero-avatar';
          newImg.id = 'ph-avatar-img'; newImg.alt = 'Avatar';
          newImg.onerror = function() { this.style.display='none'; ph.style.display='flex'; };
          ph.parentNode.insertBefore(newImg, ph);
          ph.style.display = 'none';
        }
      }
      if (live.about) document.getElementById('ph-bio').textContent = live.about;
      if (live.nip05) {
        document.getElementById('ph-nip05').textContent = live.nip05;
        document.getElementById('ph-nip05-wrap').classList.remove('hidden');
      }
      // Pas de 2e fetchStats ici — le cache localStorage garde les valeurs stables
    });
  }

  function renderProfile(p, links) {
    document.getElementById('page-loader').style.display = 'none';
    document.getElementById('profile-page').classList.remove('hidden');

    // Meta title
    document.title = `${p.cached_name || p.slug} — Nostr Map`;

    // Avatar
    const avatarWrap = document.getElementById('ph-avatar-wrap');
    const initLetter = (p.cached_name || p.slug || '?')[0].toUpperCase();
    if (p.cached_avatar) {
      avatarWrap.innerHTML = `
        <img src="${esc(p.cached_avatar)}" alt="Avatar" class="profile-hero-avatar" id="ph-avatar-img"
             data-fallback="hide" data-fallback-show="ph-avatar-placeholder" />
        <div class="avatar-placeholder" id="ph-avatar-placeholder" style="display:none;width:96px;height:96px;margin:0 auto 1rem;font-size:2rem;">${initLetter}</div>`;
    } else {
      avatarWrap.innerHTML = `
        <div class="avatar-placeholder" id="ph-avatar-placeholder" style="width:96px;height:96px;margin:0 auto 1rem;font-size:2rem;">${initLetter}</div>`;
    }

    // Nom / slug
    const displayName = p.cached_name || p.slug;
    document.getElementById('ph-name').textContent  = displayName;
    document.getElementById('ph-slug').textContent  = '@' + p.slug;
    document.title = `${displayName} — Nostr Map`;

    // Tag communauté (jamais revendiqué)
    if (p.community_added) {
      document.getElementById('ph-community-tag').classList.remove('hidden');
    }

    // NIP-05
    if (p.cached_nip05) {
      document.getElementById('ph-nip05').textContent = p.cached_nip05;
      document.getElementById('ph-nip05-wrap').classList.remove('hidden');
    }

    // Bio
    if (p.cached_bio) document.getElementById('ph-bio').textContent = p.cached_bio;

    // Score
    // Stats Nostr (depuis le cache DB)
    renderStats(p);

    // Bouton suivre : ouvre l'app Nostr sur mobile, njump sur desktop
    const followBtn = document.getElementById('ph-follow-btn');
    followBtn.href = app.getNostrProfileHref(p.npub);
    if (app.isMobileDevice()) {
      followBtn.removeAttribute('target');
      followBtn.removeAttribute('rel');
    } else {
      followBtn.setAttribute('target', '_blank');
      followBtn.setAttribute('rel', 'noopener');
    }

    // Coche verte si au moins un lien vérifié
    if (links.some(l => l.verified)) {
      document.getElementById('ph-verified-dot').classList.remove('hidden');
    }

    // Liens RS
    const linksContainer = document.getElementById('ph-links');
    if (links.length) {
      linksContainer.innerHTML = links.map(l => {
        const handle = (l.display_handle || '').replace(/^@/, '');
        const derivedUrl = l.url || (() => {
          if (l.platform === 'x' && handle)       return 'https://x.com/' + handle;
          if (l.platform === 'bluesky' && handle)  return 'https://bsky.app/profile/' + handle;
          return null;
        })();
        const inner = `
          <span class="link-platform-icon platform-${esc(l.platform)}">${app.platformIcon(l.platform)}</span>
          <div class="link-info">
            <div class="link-platform-name">${esc(app.platformName(l.platform))}</div>
            <div class="link-handle">${esc(l.display_handle || l.url)}</div>
          </div>
          ${l.verified
            ? `<span class="link-verified-dot" title="Vérifié"></span>`
            : `<span class="link-unverified-badge">Non vérifié</span>`}`;
        const safeHref = safeLinkUrl(derivedUrl);
        return safeHref
          ? `<a class="link-item" href="${esc(safeHref)}" target="_blank" rel="noopener noreferrer">${inner}</a>`
          : `<div class="link-item link-item--pending">${inner}</div>`;
      }).join('');
    }

    // URL publique
    const publicUrl = `https://nostrmap.fr/p/${p.slug}`;
    document.getElementById('ph-public-url').textContent = publicUrl;
    document.getElementById('ph-copy-url').addEventListener('click', () => {
      navigator.clipboard.writeText(publicUrl).then(() => app.toast('URL copiée !', 'success'));
    });

    document.getElementById('ph-copy-npub-btn').addEventListener('click', () => {
      navigator.clipboard.writeText(p.npub).then(() => app.toast('Npub copiée !', 'success'));
    });

    document.getElementById('ph-share-btn').addEventListener('click', () => {
      navigator.clipboard.writeText(publicUrl).then(() => app.toast('Lien copié dans le presse-papier !', 'success'));
    });

    // ── Bouton "Modifier ma fiche" ───────────────────────────────────────────
    const editBtn = document.getElementById('ph-edit-btn');

    editBtn.addEventListener('click', async () => {
      editBtn.disabled = true;
      editBtn.innerHTML = '<span class="spinner"></span> Vérification…';

      // Étape 1 : vérifier si l'extension est disponible
      if (!window.nostr) {
        location.href = '/connexion.html?next=' + encodeURIComponent(location.pathname);
        return;
      }

      try {
        // Étape 2 : lire la pubkey de l'extension (sans signature)
        const pubkeyHex = await window.nostr.getPublicKey();

        // Convertir le npub du profil en hex pour comparaison
        let profileHex;
        try { profileHex = npubToHex(p.npub); } catch { profileHex = null; }

        if (!profileHex || pubkeyHex !== profileHex) {
          app.toast('Ce profil ne vous appartient pas.', 'error');
          editBtn.disabled = false;
          editBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Modifier ma fiche`;
          return;
        }

        // Étape 3 : login NIP-07 complet (crée/met à jour le JWT)
        const result = await auth.login();
        if (!result) {
          editBtn.disabled = false;
          editBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Modifier ma fiche`;
          return;
        }

        // Étape 4 : rediriger vers la page de gestion du profil
        location.href = '/mon-profil.html';

      } catch (e) {
        app.toast('Erreur lors de la vérification.', 'error');
        editBtn.disabled = false;
        editBtn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Modifier ma fiche`;
      }
    });

    // Signalement
    const reportModal  = document.getElementById('report-modal');
    const reportBtn    = document.getElementById('ph-report-btn');
    const reportCancel = document.getElementById('report-cancel');
    const reportSubmit = document.getElementById('report-submit');

    function openReport()  { reportModal.style.display = 'flex'; document.body.style.overflow = 'hidden'; }
    function closeReport() { reportModal.style.display = 'none';  document.body.style.overflow = ''; }

    reportBtn.addEventListener('click', openReport);
    reportCancel.addEventListener('click', closeReport);
    reportModal.addEventListener('click', e => { if (e.target === reportModal) closeReport(); });

    reportSubmit.addEventListener('click', async () => {
      const reason = document.querySelector('input[name="report_reason"]:checked')?.value;
      if (!reason) { app.toast('Choisissez un motif.', 'warning'); return; }
      const details = document.getElementById('report-details').value.trim();

      reportSubmit.disabled = true;
      reportSubmit.textContent = 'Envoi…';
      try {
        const res = await fetch('/api/report.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ npub: p.npub, reason, details }),
        });
        const d = await res.json();
        if (d.success) {
          closeReport();
          app.toast('Signalement envoyé. Merci.', 'success');
        } else {
          app.toast(d.error || 'Erreur lors de l\'envoi.', 'danger');
        }
      } catch { app.toast('Erreur réseau.', 'danger'); }
      reportSubmit.disabled = false;
      reportSubmit.textContent = 'Envoyer le signalement';
    });

    // QR Code inline
    initQR(p.slug, p.npub);
  }

  function initQR(slug, npub) {
    const qrSection = document.getElementById('ph-qr-section');
    const canvas    = document.getElementById('ph-qr-canvas');
    let mode        = 'nostr';
    let qrInstance  = null;

    function makeQR() {
      canvas.innerHTML = '';
      const content = mode === 'url'
        ? `https://nostrmap.fr/p/${slug}`
        : `nostr:${npub}`;
      qrInstance = new QRCode(canvas, {
        text: content, width: 200, height: 200,
        colorDark: '#ffffff', colorLight: '#13131a',
        correctLevel: QRCode.CorrectLevel.M,
      });
    }

    document.getElementById('ph-qr-btn').addEventListener('click', () => {
      const shown = qrSection.style.display !== 'none';
      qrSection.style.display = shown ? 'none' : 'block';
      if (!shown && !qrInstance) makeQR();
      qrSection.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    });

    document.getElementById('ph-qr-close').addEventListener('click', () => {
      qrSection.style.display = 'none';
    });

    document.getElementById('ph-qr-url').addEventListener('click', function() {
      mode = 'url';
      this.classList.add('active');
      document.getElementById('ph-qr-nostr').classList.remove('active');
      makeQR();
    });
    document.getElementById('ph-qr-nostr').addEventListener('click', function() {
      mode = 'nostr';
      this.classList.add('active');
      document.getElementById('ph-qr-url').classList.remove('active');
      makeQR();
    });
    document.getElementById('ph-qr-download').addEventListener('click', () => {
      if (!qrInstance) makeQR();
      const cvs = canvas.querySelector('canvas');
      if (!cvs) return;
      const a = document.createElement('a');
      a.download = `nostrmap-${slug}-qr.png`;
      a.href = cvs.toDataURL('image/png');
      a.click();
    });
  }

  function renderStats(p) {
    let hasAny = false;

    if (p.nostr_created_at) {
      const d = new Date(p.nostr_created_at * 1000);
      const months = ['jan.','fév.','mar.','avr.','mai','juin','juil.','août','sep.','oct.','nov.','déc.'];
      document.getElementById('ph-stat-created-val').textContent =
        months[d.getMonth()] + ' ' + d.getFullYear();
      document.getElementById('ph-stat-created').classList.remove('hidden');
      hasAny = true;
    }
    if (p.nostr_posts > 0) {
      document.getElementById('ph-stat-posts-val').textContent = fmtNum(p.nostr_posts);
      document.getElementById('ph-stat-posts').classList.remove('hidden');
      hasAny = true;
    }
    if (p.nostr_followers > 0) {
      document.getElementById('ph-stat-followers-val').textContent = fmtNum(p.nostr_followers);
      document.getElementById('ph-stat-followers').classList.remove('hidden');
      hasAny = true;
    }
    if (hasAny) {
      document.getElementById('ph-stats').classList.remove('hidden');
      document.getElementById('ph-stats').style.display = 'flex';
    }
  }

  function fmtNum(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0','') + 'M';
    if (n >= 1000)    return (n / 1000).toFixed(1).replace('.0','') + 'k';
    return String(n);
  }

  function showNotFound() {
    document.getElementById('page-loader').style.display = 'none';
    document.getElementById('profile-404').classList.remove('hidden');
    document.title = 'Profil introuvable — Nostr Map';
  }

  // Lien cliquable : uniquement https:// (jamais javascript:, data:, etc.).
  function safeLinkUrl(value) {
    if (typeof value !== 'string' || !value) return '';
    try {
      const u = new URL(value.trim());
      return u.protocol === 'https:' ? u.href : '';
    } catch { return ''; }
  }

  function esc(str) {
    if (!str) return '';
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
