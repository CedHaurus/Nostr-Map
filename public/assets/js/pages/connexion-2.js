  import { auth } from '/assets/js/auth.js?v=20260423-modal';
  import { app }  from '/assets/js/app.js?v=20261106-csp';

  auth.initUI({
    loginBtn:  document.getElementById('btn-login-header'),
    logoutBtn: document.getElementById('btn-logout'),
    userMenu:  document.getElementById('user-menu'),
    slugEl:    document.getElementById('user-slug-display'),
  });

  function safeNext(raw) {
    return (raw && /^\/[^/]/.test(raw)) ? raw : '/mon-profil.html';
  }

  if (auth.isLoggedIn()) {
    location.href = safeNext(new URLSearchParams(location.search).get('next'));
  }

  if (new URLSearchParams(location.search).get('from') === 'ajouter') {
    document.getElementById('cx-ajouter-banner').style.display = 'flex';
  }

  // ── Connexion via nsec (partagé desktop + mobile) ────────────────────────
  const LOGIN_SVG = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>`;

  function setupNsecForm(inputId, btnId, statusId) {
    const input  = document.getElementById(inputId);
    const btn    = document.getElementById(btnId);
    const status = document.getElementById(statusId);
    if (!input || !btn) return;

    btn.addEventListener('click', async () => {
      const nsec = input.value.trim();
      if (!nsec) { status.innerHTML = '<strong style="color:var(--warning)">⚠ Entrez votre clé nsec.</strong>'; return; }
      if (!nsec.startsWith('nsec1')) { status.innerHTML = '<strong style="color:var(--warning)">⚠ La clé doit commencer par nsec1…</strong>'; return; }

      btn.disabled = true;
      btn.innerHTML = '<span class="spinner"></span> Connexion…';
      status.textContent = '';

      // Effacer le champ avant même l'envoi réseau
      input.value = '';

      const result = await auth.loginWithNsec(nsec);
      if (result) {
        app.toast('Connecté ! Bienvenue 🎉', 'success');
        setTimeout(() => {
          location.href = safeNext(new URLSearchParams(location.search).get('next'));
        }, 800);
      } else {
        btn.disabled = false;
        btn.innerHTML = LOGIN_SVG + ' Se connecter';
        status.innerHTML = '<strong style="color:var(--warning)">⚠ Connexion échouée.</strong> Vérifiez votre clé nsec et réessayez.';
      }
    });

    // Connexion au Enter dans le champ
    input.addEventListener('keydown', e => { if (e.key === 'Enter') btn.click(); });
  }

  // ── Détection mobile / tablette (pas d'extension navigateur dispo) ──────────
  const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent)
                || (navigator.maxTouchPoints > 1 && /Macintosh/i.test(navigator.userAgent))
                || (window.innerWidth <= 1024 && navigator.maxTouchPoints > 0);

  if (isMobile) {
    document.getElementById('cx-mobile').style.display  = '';
    document.getElementById('cx-desktop').style.display = 'none';

    initNsecToggle('nsec-toggle-mobile', 'nsec-input-mobile', 'nsec-eye-off-mobile', 'nsec-eye-on-mobile');
    setupNsecForm('nsec-input-mobile', 'btn-connect-nsec-mobile', 'cx-nsec-status-mobile');

    // Détection NIP-07 mobile (ex: Nostash sur iOS Safari) — délai 400ms pour laisser l'extension s'injecter
    const nip07Box    = document.getElementById('cx-nip07-detected');
    const nip07Btn    = document.getElementById('btn-connect-nip07-mobile');
    const nip07Status = document.getElementById('cx-nsec-status-mobile');

    function checkNip07() {
      if (window.nostr) {
        nip07Box.style.display = '';
        nip07Box.style.opacity = '0';
        nip07Box.style.transform = 'translateY(-6px)';
        nip07Box.style.transition = 'opacity .3s ease, transform .3s ease';
        requestAnimationFrame(() => requestAnimationFrame(() => {
          nip07Box.style.opacity = '1';
          nip07Box.style.transform = 'translateY(0)';
        }));
      }
    }
    setTimeout(checkNip07, 400);

    document.getElementById('cx-nip07-dismiss').addEventListener('click', () => {
      nip07Box.style.opacity = '0';
      setTimeout(() => { nip07Box.style.display = 'none'; }, 250);
    });

    nip07Btn.addEventListener('click', async () => {
      nip07Btn.disabled = true;
      nip07Btn.innerHTML = '<span class="spinner"></span> Connexion…';
      const result = await auth.login();
      if (result) {
        app.toast('Connecté ! Bienvenue 🎉', 'success');
        setTimeout(() => {
          location.href = safeNext(new URLSearchParams(location.search).get('next'));
        }, 800);
      } else {
        nip07Btn.disabled = false;
        nip07Btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0;"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg> Se connecter avec mon extension';
      }
    });

  } else {
    document.getElementById('cx-desktop').style.display = '';
    document.getElementById('cx-mobile').style.display  = 'none';

    // ── Onglet Extension ─────────────────────────────────────────────────
    const isFirefox = /Firefox\/\d/i.test(navigator.userAgent);
    const isEdge    = /Edg\//i.test(navigator.userAgent);
    const isBrave   = navigator.brave !== undefined;
    const browser   = isFirefox ? 'Firefox' : isEdge ? 'Edge' : isBrave ? 'Brave' : 'Chrome';

    const ffUrl  = 'https://addons.mozilla.org/fr/firefox/addon/nostr-map-signer/';
    const chrUrl = 'https://chromewebstore.google.com/detail/nostr-map-signer/hceiololfodfnhcaehnfijplkhbepbhd';

    const extLinks = document.getElementById('ext-links');
    if (isFirefox) {
      extLinks.innerHTML = `
        <a href="${ffUrl}" target="_blank" rel="noopener" class="cx-ext-btn cx-ext-btn-primary">
          Nostr Map Signer <span class="cx-browser-badge">Firefox</span> ↗
        </a>
        <a href="${chrUrl}" target="_blank" rel="noopener" class="cx-ext-btn">
          Nostr Map Signer <span class="cx-browser-badge">Chrome / Edge / Brave</span> ↗
        </a>`;
    } else {
      extLinks.innerHTML = `
        <a href="${chrUrl}" target="_blank" rel="noopener" class="cx-ext-btn cx-ext-btn-primary">
          Nostr Map Signer <span class="cx-browser-badge">${browser}</span> ↗
        </a>
        <a href="${ffUrl}" target="_blank" rel="noopener" class="cx-ext-btn">
          Nostr Map Signer <span class="cx-browser-badge">Firefox</span> ↗
        </a>`;
    }

    const detectedGuide = isFirefox ? 'guide-firefox' : 'guide-chrome';
    const detectedBadge = isFirefox ? 'badge-firefox'  : 'badge-chrome';
    document.getElementById(detectedGuide).classList.add('open');
    document.getElementById(detectedBadge).textContent = (isFirefox ? 'Firefox' : browser) + ' — votre navigateur';
    document.getElementById(detectedBadge).classList.add('recommended');

    const btnExt    = document.getElementById('btn-connect');
    const statusExt = document.getElementById('cx-status');

    btnExt.addEventListener('click', async () => {
      if (!window.nostr) {
        statusExt.innerHTML = '<strong style="color:var(--warning)">⚠ Extension non détectée.</strong> Installez l\'extension (étape 1) puis rechargez cette page.';
        return;
      }
      btnExt.disabled = true;
      btnExt.innerHTML = '<span class="spinner"></span> Connexion…';
      statusExt.textContent = '';

      const result = await auth.login();
      if (result) {
        app.toast('Connecté ! Bienvenue 🎉', 'success');
        setTimeout(() => {
          location.href = safeNext(new URLSearchParams(location.search).get('next'));
        }, 800);
      } else {
        btnExt.disabled = false;
        btnExt.innerHTML = LOGIN_SVG + ' Se connecter';
      }
    });

    window.addEventListener('nostr:no-extension', () => {
      statusExt.innerHTML = '<strong style="color:var(--warning)">⚠ Extension non détectée.</strong> Installez l\'extension (étape 1) puis rechargez cette page.';
    });

    // ── Onglet Nsec ──────────────────────────────────────────────────────
    initNsecToggle('nsec-toggle', 'nsec-input', 'nsec-eye-off', 'nsec-eye-on');
    setupNsecForm('nsec-input', 'btn-connect-nsec', 'cx-nsec-status');
  }
