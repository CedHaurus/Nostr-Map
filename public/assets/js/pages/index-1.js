  import { app }    from '/assets/js/app.js?v=20261106-csp';
  import { auth }   from '/assets/js/auth.js?v=20260423-modal';
  import { initQR } from '/assets/js/qr.js?v=20261106-csp';
  import { nostr }  from '/assets/js/nostr.js';

  // npub → hex (bech32 minimal)
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

  // N'accepte qu'une adresse d'image http(s) absolue ; tout le reste est écarté.
  function safeImageUrl(value) {
    if (typeof value !== 'string' || value.length > 2000) return '';
    try {
      const u = new URL(value.trim());
      return (u.protocol === 'https:' || u.protocol === 'http:') ? u.href : '';
    } catch { return ''; }
  }

  // Met à jour une carte sans cache depuis les relais Nostr + sauvegarde en DB
  function liveUpdateCard(slug, npub) {
    let pubHex;
    try { pubHex = npubToHex(npub); } catch {}

    nostr.fetchProfile(npub, (live) => {
      if (!live) return;
      const nameEl = document.getElementById('card-name-' + slug);
      const avEl   = document.getElementById('card-av-' + slug);
      if (nameEl && (live.display_name || live.name))
        nameEl.textContent = live.display_name || live.name;
      const pictureUrl = safeImageUrl(live.picture);
      if (avEl && pictureUrl) {
        // Construction par le DOM : la valeur vient des relais Nostr (non fiable),
        // elle ne doit jamais être interprétée comme du HTML.
        const letter = String(live.display_name || live.name || slug || '?').charAt(0).toUpperCase();
        const img = document.createElement('img');
        img.className = 'avatar';
        img.alt = '';
        img.loading = 'lazy';
        const placeholder = document.createElement('div');
        placeholder.className = 'avatar-placeholder';
        placeholder.style.display = 'none';
        placeholder.textContent = letter;
        img.addEventListener('error', () => {
          img.style.display = 'none';
          placeholder.style.display = 'flex';
        });
        img.src = pictureUrl;
        avEl.replaceChildren(img, placeholder);
      }
      if (live.about) {
        const bioText = live.about.length > 220 ? live.about.slice(0, 217) + '…' : live.about;
        const card = document.querySelector(`.profile-card[data-npub="${npub}"]`);
        let bioEl = document.getElementById('card-bio-' + slug);
        if (!bioEl) {
          const content = card?.querySelector('.profile-card-content');
          if (content) {
            bioEl = document.createElement('p');
            bioEl.className = 'profile-bio';
            bioEl.id = 'card-bio-' + slug;
            content.appendChild(bioEl);
          }
        }
        if (bioEl) {
          bioEl.textContent = bioText;
          bioEl.classList.remove('is-empty');
        }
        card?.classList.remove('profile-card-no-bio');
      }

      // Stats live (affichage seulement, pas de persistance côté public)
      if (pubHex) {
        nostr.fetchStats(pubHex, null, (stats) => {
          const fEl = document.getElementById('card-followers-' + slug);
          const pEl = document.getElementById('card-posts-'     + slug);
          if (fEl && stats.followers > 0) fEl.textContent = app.fmtNum(stats.followers) + ' abon.';
          if (pEl && stats.posts     > 0) pEl.textContent = app.fmtNum(stats.posts)     + ' notes';
        });
      }
    });
  }

  // Init auth UI
  auth.initUI({
    loginBtn:  document.getElementById('btn-login'),
    logoutBtn: document.getElementById('btn-logout'),
    userMenu:  document.getElementById('user-menu'),
    slugEl:    document.getElementById('user-slug-display'),
  });

  // Init QR modal
  initQR();

  // ── Rafraîchissement silencieux en arrière-plan ──────────────────
  async function refreshGridStats(profiles) {
    if (!profiles || !profiles.length) return;
    await Promise.all(profiles.map(async (p) => {
      if (!p.npub || !p.slug) return;

      // 1. Appliquer immédiatement le cache localStorage si frais
      const cached = app.getCachedStats(p.npub);
      if (cached) {
        const fEl = document.getElementById('card-followers-' + p.slug);
        const pEl = document.getElementById('card-posts-'     + p.slug);
        if (fEl && cached.followers > 0) fEl.textContent = app.fmtNum(cached.followers) + ' abon.';
        if (pEl && cached.posts     > 0) pEl.textContent = app.fmtNum(cached.posts)     + ' notes';
        // Mettre à jour les data attributes pour le tri live
        const wrap = grid.querySelector(`[data-slug="${p.slug}"]`);
        if (wrap) {
          if (cached.followers > 0) wrap.dataset.liveFollowers = cached.followers;
          if (cached.posts     > 0) wrap.dataset.livePosts     = cached.posts;
        }
      }

      // 2. Fetch nostr.band en arrière-plan pour rafraîchir le cache
      let hex;
      try { hex = npubToHex(p.npub); } catch { return; }
      try {
        const res = await fetch(`https://api.nostr.band/v0/stats/profile/${hex}`);
        if (!res.ok) return;
        const data = await res.json();
        const s = data?.stats?.[hex];
        if (!s) return;
        const followers = s.followers_pubkey_count ?? 0;
        const posts     = s.note_count ?? 0;
        // Sauvegarder en localStorage
        app.setCachedStats(p.npub, { followers, posts });
        // Mettre à jour le DOM et les data attributes
        const fEl = document.getElementById('card-followers-' + p.slug);
        const pEl = document.getElementById('card-posts-'     + p.slug);
        if (fEl) fEl.textContent = followers > 0 ? app.fmtNum(followers) + ' abon.' : '';
        if (pEl) pEl.textContent = posts     > 0 ? app.fmtNum(posts)     + ' notes' : '';
        const wrap = grid.querySelector(`[data-slug="${p.slug}"]`);
        if (wrap) {
          wrap.dataset.liveFollowers = followers;
          wrap.dataset.livePosts     = posts;
        }
        // Les statistiques en base sont tenues par le serveur (tâche horaire) :
        // un visiteur ne les écrit plus, il ne fait que les afficher.
      } catch {}
    }));

    // Re-trier le DOM selon les valeurs live si on est en mode classement
    if (['actifs', 'populaires'].includes(_currentSort)) {
      resortGrid();
    }
  }

  function resortGrid() {
    const key   = _currentSort === 'actifs' ? 'livePosts' : 'liveFollowers';
    const wraps = [...grid.querySelectorAll('[data-slug]')];
    wraps.sort((a, b) => (parseInt(b.dataset[key]) || 0) - (parseInt(a.dataset[key]) || 0));

    // Retirer les badges existants
    grid.querySelectorAll('.rank-badge').forEach(el => el.remove());
    grid.querySelectorAll('.profile-card').forEach(el => {
      el.classList.remove('rank-1', 'rank-2', 'rank-3');
    });

    // Réinsérer dans le bon ordre + rebadger le podium
    wraps.forEach((wrap, i) => {
      grid.appendChild(wrap);
      if (i < 3) {
        const card = wrap.querySelector('.profile-card');
        if (card) {
          card.classList.add(`rank-${i + 1}`);
          const badge = document.createElement('div');
          badge.className = `rank-badge rank-badge-${i + 1}`;
          badge.textContent = i + 1;
          card.appendChild(badge);
        }
      }
    });
  }

  // ── Infinite scroll ──────────────────────────────────────────────
  const grid     = document.getElementById('grid-main');
  const sentinel = document.getElementById('grid-sentinel');

  function mkSkeletons(n) {
    return Array.from({length: n}, () =>
      `<div class="card" style="height:228px;"><div class="skeleton" style="height:100%;border-radius:10px;"></div></div>`
    ).join('');
  }

  // État par onglet
  const tabState = {};
  ['recent','actifs','populaires','random','verified'].forEach(s => {
    tabState[s] = { offset: 0, loading: false, done: false, seed: null };
  });

  function resetTab(sort) {
    tabState[sort].offset  = 0;
    tabState[sort].loading = false;
    tabState[sort].done    = false;
    tabState[sort].seed    = sort === 'random' ? Math.floor(Math.random() * 1e9) : null;
  }

  async function loadPage(sort, append) {
    const s = tabState[sort];
    if (s.loading || s.done) return;
    s.loading = true;

    if (!append) {
      grid.innerHTML = mkSkeletons(8);
    } else {
      // Petits skeletons en bas pendant le chargement
      const loader = document.createElement('div');
      loader.id = 'load-more-skeletons';
      loader.style.cssText = 'grid-column:1/-1;display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:1rem;';
      loader.innerHTML = mkSkeletons(4);
      grid.appendChild(loader);
    }

    try {
      const params = new URLSearchParams({ sort, offset: s.offset });
      if (s.seed !== null) params.set('seed', s.seed);
      const res     = await fetch(`/api/search.php?${params}`);
      const data    = await res.json();
      const profiles = data.results || [];

      // Retirer les skeletons de chargement
      document.getElementById('load-more-skeletons')?.remove();

      if (!append) grid.innerHTML = '';

      if (!profiles.length && !append) {
        grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">
          <div class="empty-state-icon">👻</div><p>Aucun profil pour le moment.</p></div>`;
        s.done = true;
        s.loading = false;
        return;
      }

      const ranked = ['actifs', 'populaires'].includes(sort) && !append;
      profiles.forEach((p, i) => {
        const links = (p.verified_platforms || []).map(pl => ({ platform: pl, verified: true }));
        const wrap  = document.createElement('div');
        wrap.className = 'card-reveal';
        wrap.dataset.slug        = p.slug;
        wrap.dataset.livePosts     = p.nostr_posts     || 0;
        wrap.dataset.liveFollowers = p.nostr_followers || 0;
        wrap.innerHTML = app.renderCard(p, links);

        // Badge podium sur les 3 premiers (uniquement 1ère page, onglets classés)
        if (ranked && i < 3) {
          const card = wrap.querySelector('.profile-card');
          if (card) {
            card.classList.add(`rank-${i + 1}`);
            const badge = document.createElement('div');
            badge.className = `rank-badge rank-badge-${i + 1}`;
            badge.textContent = i + 1;
            card.appendChild(badge);
          }
        }

        grid.appendChild(wrap);
        cardRevealObserver.observe(wrap);
        if (!p.cached_name && !p.cached_avatar) liveUpdateCard(p.slug, p.npub);
      });

      // Rafraîchir les stats de tous les profils depuis nostr.band (navigateur)
      refreshGridStats(profiles);

      s.offset += profiles.length;
      s.done    = !data.has_more;

    } catch (e) {
      document.getElementById('load-more-skeletons')?.remove();
      if (!append) grid.innerHTML = `<div class="empty-state" style="grid-column:1/-1;">
        <p class="text-muted">Erreur de chargement.</p></div>`;
    }
    s.loading = false;
  }

  // Observer le sentinel (infinite scroll)
  const observer = new IntersectionObserver(entries => {
    if (entries[0].isIntersecting) loadPage(_currentSort, true);
  }, { rootMargin: '300px' });
  observer.observe(sentinel);

  // Observer scroll-reveal pour les cartes
  const cardRevealObserver = new IntersectionObserver(entries => {
    entries.forEach(e => {
      if (e.isIntersecting) {
        e.target.classList.add('in-view');
        cardRevealObserver.unobserve(e.target);
      }
    });
  }, { rootMargin: '0px 0px -40px 0px', threshold: 0.05 });

  // Barre de filtres
  let _currentSort = 'recent';
  const filterBtns = document.querySelectorAll('.filter-btn');
  const sortDropdown     = document.getElementById('sort-dropdown');
  const sortDropdownList = document.getElementById('sort-dropdown-list');
  const sortDropdownLabel = document.getElementById('sort-dropdown-label');
  const sortDropdownIcon  = document.getElementById('sort-dropdown-icon');
  const sortOptions       = sortDropdownList ? sortDropdownList.querySelectorAll('.mob-dropdown-option') : [];

  function syncFilterUI(sort) {
    filterBtns.forEach(b => b.classList.toggle('active', b.dataset.sort === sort));
    if (sortDropdownLabel) {
      const opt = sortDropdownList.querySelector(`[data-sort="${sort}"]`);
      if (opt) {
        // Mettre à jour le label
        sortDropdownLabel.textContent = opt.querySelector('svg') ? opt.textContent.trim() : opt.textContent;
        // Mettre à jour l'icône du trigger
        if (sortDropdownIcon) {
          const optSvg = opt.querySelector('svg');
          if (optSvg) {
            sortDropdownIcon.innerHTML = optSvg.innerHTML;
            sortDropdownIcon.setAttribute('viewBox', optSvg.getAttribute('viewBox') || '0 0 24 24');
            sortDropdownIcon.dataset.sort = sort;
          }
        }
      }
    }
    sortOptions.forEach(o => o.classList.toggle('active', o.dataset.sort === sort));
  }

  function applySort(sort) {
    if (sort === _currentSort) return;
    _currentSort = sort;
    syncFilterUI(sort);
    resetTab(sort);
    loadPage(sort, false);
  }

  filterBtns.forEach(btn => {
    btn.addEventListener('click', () => applySort(btn.dataset.sort));
  });

  // Dropdown mobile custom
  if (sortDropdown) {
    sortDropdown.addEventListener('click', () => {
      const open = sortDropdownList.classList.toggle('open');
      sortDropdown.setAttribute('aria-expanded', open);
    });
    sortDropdown.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        const open = sortDropdownList.classList.toggle('open');
        sortDropdown.setAttribute('aria-expanded', open);
      } else if (e.key === 'Escape') {
        sortDropdownList.classList.remove('open');
        sortDropdown.setAttribute('aria-expanded', 'false');
      }
    });
    sortOptions.forEach(opt => {
      opt.addEventListener('click', () => {
        applySort(opt.dataset.sort);
        sortDropdownList.classList.remove('open');
        sortDropdown.setAttribute('aria-expanded', 'false');
        sortDropdown.focus();
      });
    });
    document.addEventListener('click', e => {
      if (!sortDropdown.contains(e.target) && !sortDropdownList.contains(e.target)) {
        sortDropdownList.classList.remove('open');
        sortDropdown.setAttribute('aria-expanded', 'false');
      }
    });
  }

  // Chargement initial — déclenché en fin de script après init recherche

  // ── Recherche pleine page ────────────────────────────────────────
  const searchInput   = document.getElementById('search-input');
  const sectionBrowse = document.getElementById('section-browse');
  const sectionSearch = document.getElementById('section-search');
  const searchGrid    = document.getElementById('search-grid');
  const searchReset   = document.getElementById('search-reset');
  let   srTimer       = null;

  function enterSearch() {
    sectionBrowse.classList.add('hidden');
    sectionSearch.classList.remove('hidden');
    searchReset.classList.remove('hidden');
  }

  function exitSearch(clearInput) {
    sectionSearch.classList.add('hidden');
    sectionBrowse.classList.remove('hidden');
    searchReset.classList.add('hidden');
    searchGrid.innerHTML = '';
    if (clearInput) searchInput.value = '';
  }

  async function runSearch(q) {
    const skels = Array(6).fill('<div style="height:228px;"><div class="skeleton" style="height:100%;border-radius:10px;"></div></div>').join('');
    searchGrid.innerHTML = skels;
    try {
      const res     = await fetch('/api/search.php?q=' + encodeURIComponent(q) + '&limit=40');
      const data    = await res.json();
      const results = data.results || [];
      if (!results.length) {
        searchGrid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><div class="empty-state-icon">🔍</div><p>Aucun résultat pour « ' + q.replace(/</g,'&lt;') + ' »</p></div>';
        return;
      }
      const html = results.map((p, i) => {
        const links = (p.verified_platforms || []).map(pl => ({ platform: pl, verified: true }));
        return '<div class="fade-in fade-in-delay-' + Math.min(i+1,3) + '">' + app.renderCard(p, links) + '</div>';
      }).join('');
      searchGrid.innerHTML = html;
      refreshGridStats(results);
    } catch(e) {
      searchGrid.innerHTML = '<div class="empty-state" style="grid-column:1/-1;"><p>Erreur lors de la recherche.</p></div>';
    }
  }

  searchInput.addEventListener('input', function() {
    clearTimeout(srTimer);
    const q = this.value.trim();
    if (q.length < 2) { exitSearch(false); return; }
    enterSearch();
    srTimer = setTimeout(() => runSearch(q), 300);
  });

  searchInput.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') exitSearch(true);
  });

  searchReset.addEventListener('click', function() { exitSearch(true); searchInput.focus(); });

  // ── Retour arrière : sauvegarde / restauration de l'état ─────────────
  //
  // Problème : les fiches profil (/p/slug) sont des pages distinctes.
  // Quand l'utilisateur fait "←", le navigateur recharge index.html et
  // la liste repart de zéro (tri "Derniers inscrits", scroll en haut).
  //
  // Solution : sessionStorage.
  //   • Avant de naviguer vers /p/slug, on sauvegarde :
  //       - Pour la liste  : { type, sort, offset, scrollY }
  //       - Pour la recherche : { type, query, scrollY }
  //   • Au chargement suivant, si performance.navigation.type === 'back_forward',
  //     on relit l'état et on restaure le tri + le scroll (liste) ou la
  //     requête + le scroll (recherche).
  //
  // Note scroll : les cartes ont une hauteur fixe, donc scrollY est fiable.
  // On attend 80 ms après le dernier rendu pour laisser le DOM se stabiliser.

  function saveNavState() {
    const inSearch = !sectionSearch.classList.contains('hidden');
    if (inSearch) {
      const q = searchInput.value.trim();
      if (q.length >= 2) {
        sessionStorage.setItem('nm_nav', JSON.stringify({
          type: 'search', query: q, scrollY: window.scrollY
        }));
      }
    } else {
      sessionStorage.setItem('nm_nav', JSON.stringify({
        type:   'browse',
        sort:   _currentSort,
        offset: tabState[_currentSort].offset,
        scrollY: window.scrollY
      }));
    }
  }

  // Intercepte les clics sur les cartes (liste et résultats de recherche)
  // en phase de capture pour s'exécuter avant location.href / suivi du <a>.
  document.addEventListener('click', e => {
    if (e.target.closest('.profile-card, .profile-card-body, .search-result-item')) {
      saveNavState();
    }
  }, true);

  async function tryRestoreNavState() {
    const nav = performance.getEntriesByType('navigation')[0];
    if (!nav || nav.type !== 'back_forward') {
      sessionStorage.removeItem('nm_nav');
      return false;
    }
    const raw = sessionStorage.getItem('nm_nav');
    if (!raw) return false;
    sessionStorage.removeItem('nm_nav');

    let state;
    try { state = JSON.parse(raw); } catch { return false; }

    if (state.type === 'browse') {
      _currentSort = state.sort;
      syncFilterUI(state.sort);
      resetTab(state.sort);
      // Recharge page par page jusqu'à l'offset mémorisé.
      // Chaque appel loadPage() incrémente tabState[sort].offset de PAGE items.
      // La sortie de secours (prevOffset === offset) prévient toute boucle infinie
      // si l'API retourne moins d'items qu'attendu (profils supprimés, etc.).
      let first = true;
      while (
        tabState[state.sort].offset < (state.offset || 0) &&
        !tabState[state.sort].done
      ) {
        const prev = tabState[state.sort].offset;
        await loadPage(state.sort, !first);
        first = false;
        if (tabState[state.sort].offset === prev) break;
      }
      setTimeout(() => window.scrollTo({ top: state.scrollY, behavior: 'instant' }), 80);
      return true;
    }

    if (state.type === 'search') {
      searchInput.value = state.query;
      searchReset.classList.remove('hidden');
      enterSearch();
      await runSearch(state.query);
      setTimeout(() => window.scrollTo({ top: state.scrollY, behavior: 'instant' }), 80);
      return true;
    }

    return false;
  }

  // Chargement initial — ou restauration si retour depuis une fiche profil.
  // L'IIFE async permet d'await tryRestoreNavState() sans top-level await
  // et garantit que tous les const (searchInput, sectionSearch…) sont déjà
  // initialisés avant l'exécution.
  (async () => {
    const restored = await tryRestoreNavState();
    if (!restored) {
      resetTab('recent');
      syncFilterUI('recent');
      loadPage('recent', false);
    }
  })();
