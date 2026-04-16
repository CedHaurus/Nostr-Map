/**
 * app.js — Nostr Map
 * Fonctions utilitaires UI : rendu cards, grilles, recherche, toasts.
 */

// Année courante dans le footer + hamburger menu mobile + hide on scroll
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.footer-year').forEach(el => {
    el.textContent = new Date().getFullYear();
  });

  // ── Header hide/show au scroll ─────────────────────────────────
  const header = document.querySelector('.header');
  if (header) {
    let lastY = 0;
    window.addEventListener('scroll', () => {
      const y = window.scrollY;
      if (y > lastY && y > 80) {
        header.classList.add('header-hidden');
      } else {
        header.classList.remove('header-hidden');
      }
      lastY = y;
    }, { passive: true });
  }

  // ── Hamburger menu mobile ──────────────────────────────────────
  const hamburger  = document.getElementById('hamburger');
  const mobileNav  = document.getElementById('mobile-nav');
  const overlay    = document.getElementById('mobile-nav-overlay');
  const closeBtn   = document.getElementById('mobile-nav-close');

  function openMenu() {
    mobileNav?.classList.add('open');
    hamburger?.classList.add('open');
    hamburger?.setAttribute('aria-expanded', 'true');
    document.body.style.overflow = 'hidden';
  }
  function closeMenu() {
    mobileNav?.classList.remove('open');
    hamburger?.classList.remove('open');
    hamburger?.setAttribute('aria-expanded', 'false');
    document.body.style.overflow = '';
  }

  hamburger?.addEventListener('click', () =>
    mobileNav?.classList.contains('open') ? closeMenu() : openMenu()
  );
  overlay?.addEventListener('click', closeMenu);
  closeBtn?.addEventListener('click', closeMenu);

  // Fermer sur navigation (clic sur un lien du drawer)
  mobileNav?.querySelectorAll('a.mobile-nav-item').forEach(a =>
    a.addEventListener('click', closeMenu)
  );
});

function fmtNum(n) {
  if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0','') + 'M';
  if (n >= 1000)    return (n / 1000).toFixed(1).replace('.0','') + 'k';
  return String(n);
}

// ─── Plateforme helpers ──────────────────────────────────────────────────────

const PLATFORMS = {
  x:        { name: 'X / Twitter', icon: '𝕏' },
  mastodon: { name: 'Mastodon',    icon: '🐘' },
  bluesky:  { name: 'Bluesky',     icon: '🦋' },
  youtube:  { name: 'YouTube',     icon: '▶️' },
};

function platformIcon(p) { return PLATFORMS[p]?.icon || '🔗'; }
function platformName(p) { return PLATFORMS[p]?.name || p; }

// ─── Liens profil Nostr : app mobile si possible, web sinon ───────────────────
function getUserAgent() {
  return navigator.userAgent || '';
}

function isIosDevice() {
  return /iPhone|iPad|iPod/i.test(getUserAgent());
}

function isAndroidDevice() {
  return /Android/i.test(getUserAgent());
}

function isAndroidChromium() {
  return isAndroidDevice() && /(Chrome|Chromium|SamsungBrowser|EdgA|OPR)/i.test(getUserAgent());
}

function isMobileDevice() {
  return isIosDevice() || isAndroidDevice();
}

function getNostrWebProfileUrl(npub) {
  return `https://njump.me/${encodeURIComponent(npub)}`;
}

function getNostrProfileHref(npub) {
  const webUrl = getNostrWebProfileUrl(npub);

  // Chrome/Chromium Android gère bien intent: avec un fallback web natif.
  if (isAndroidChromium()) {
    return `intent:${npub}#Intent;scheme=nostr;action=android.intent.action.VIEW;S.browser_fallback_url=${encodeURIComponent(webUrl)};end`;
  }

  // iOS et autres navigateurs mobiles : on laisse le système router le scheme.
  if (isMobileDevice()) {
    return `nostr:${npub}`;
  }

  return webUrl;
}

// ─── Escape HTML ─────────────────────────────────────────────────────────────

function esc(str) {
  if (str == null) return '';
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ─── Render profile card ─────────────────────────────────────────────────────

function renderCard(profile, links = [], opts = {}) {
  const { compact = false } = opts;

  const name   = esc(profile.cached_name || profile.slug || '?');
  const slug   = esc(profile.slug);
  const bio    = profile.cached_bio
    ? esc(profile.cached_bio.length > 100 ? profile.cached_bio.slice(0, 97) + '…' : profile.cached_bio)
    : '';

  // Avatar
  const avatarHtml = profile.cached_avatar
    ? `<img class="avatar" src="${esc(profile.cached_avatar)}" alt="${name}" loading="lazy" onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" /><div class="avatar-placeholder" style="display:none;">${name[0].toUpperCase()}</div>`
    : `<div class="avatar-placeholder">${name[0].toUpperCase()}</div>`;

  // Badge vérifié (au moins un lien vérifié)
  const verifiedLinks = links.filter(l => l.verified);
  const verifiedDot = verifiedLinks.length
    ? `<span class="verified-dot" title="Profil vérifié — liens vérifiés">✓</span>`
    : '';

  // Badges RS
  const badgesHtml = links.length
    ? `<div class="badges">${links.slice(0, 6).map(l => `
        <span class="badge ${l.verified ? 'badge-verified' : 'badge-unverified'}" title="${esc(platformName(l.platform))} ${l.verified ? '(vérifié)' : '(non vérifié)'}">
          <span class="badge-icon">${platformIcon(l.platform)}</span>
          ${l.verified ? '✓' : ''}
        </span>`).join('')}
      </div>`
    : '';

  // Stats (toujours rendu avec ids pour mise à jour live)
  const fStr = profile.nostr_followers > 0 ? fmtNum(profile.nostr_followers) + ' abon.' : '';
  const pStr = profile.nostr_posts > 0     ? fmtNum(profile.nostr_posts) + ' notes'     : '';
  const statsHtml = `
    <div class="card-stats" id="card-stats-${slug}">
      <span id="card-followers-${slug}">${fStr}</span>
      <span id="card-posts-${slug}">${pStr}</span>
    </div>`;
  const followHref = esc(getNostrProfileHref(profile.npub));
  const followAttrs = isMobileDevice()
    ? ''
    : ' target="_blank" rel="noopener"';

  const actions = compact ? '' : `
    <div class="profile-card-actions">
      <a class="card-action-btn" href="${followHref}"${followAttrs} onclick="event.stopPropagation()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75M9 3a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/>
        </svg>
        Suivre
      </a>
      <button class="card-action-btn" onclick="event.stopPropagation();window._openQR('${esc(profile.slug)}','${esc(profile.npub)}')">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
          <rect x="3" y="14" width="7" height="7"/>
        </svg>
        QR
      </button>
    </div>`;

  const hasCached = !!(profile.cached_name || profile.cached_avatar);
  return `
    <div class="profile-card" data-npub="${esc(profile.npub)}" data-no-cache="${hasCached ? '' : '1'}"
         onclick="location.href='/p/${slug}'">
      <a href="/p/${slug}" class="profile-card-body" id="card-av-${slug}" onclick="event.stopPropagation()">
        <div class="avatar-wrap">${avatarHtml}</div>
        <div class="profile-card-info">
          <div class="profile-name-row">
            <div class="profile-name" id="card-name-${slug}">${name}</div>
            ${verifiedDot}
          </div>
          <div class="profile-slug">@${slug}</div>
          ${statsHtml}
        </div>
      </a>
      ${actions}
    </div>`;
}

// ─── Charger une grille ──────────────────────────────────────────────────────

async function loadGrid(containerId, url) {
  const container = document.getElementById(containerId);
  if (!container) return [];

  try {
    const res  = await fetch(url);
    const data = await res.json();
    const profiles = data.results || [];

    if (!profiles.length) {
      container.innerHTML = `
        <div class="empty-state" style="grid-column:1/-1;">
          <div class="empty-state-icon">👻</div>
          <p>Aucun profil pour le moment.</p>
        </div>`;
      return [];
    }

    container.innerHTML = profiles.map((p, i) => {
      const links = (p.verified_platforms || []).map(pl => ({ platform: pl, verified: true }));
      return `<div class="fade-in fade-in-delay-${Math.min(i+1,3)}">${renderCard(p, links)}</div>`;
    }).join('');

    return profiles;

  } catch (e) {
    container.innerHTML = `
      <div class="empty-state" style="grid-column:1/-1;">
        <p class="text-muted">Erreur de chargement.</p>
      </div>`;
    return [];
  }
}

// ─── Recherche ───────────────────────────────────────────────────────────────

let _searchTimer = null;

function initSearch(inputEl, resultsEl) {
  if (!inputEl || !resultsEl) return;

  inputEl.addEventListener('input', () => {
    clearTimeout(_searchTimer);
    const q = inputEl.value.trim();

    if (q.length < 2) {
      resultsEl.classList.add('hidden');
      resultsEl.innerHTML = '';
      return;
    }

    _searchTimer = setTimeout(() => doSearch(q, resultsEl), 280);
  });

  // Fermer au clic extérieur
  document.addEventListener('click', (e) => {
    if (!inputEl.contains(e.target) && !resultsEl.contains(e.target)) {
      resultsEl.classList.add('hidden');
    }
  });

  // Soumettre avec Entrée
  inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      resultsEl.classList.add('hidden');
      inputEl.blur();
    }
  });
}

async function doSearch(q, resultsEl) {
  try {
    const res  = await fetch(`/api/search.php?q=${encodeURIComponent(q)}`);
    const data = await res.json();
    const results = data.results || [];

    if (!results.length) {
      resultsEl.innerHTML = `<div class="search-result-item" style="color:var(--text-muted);">Aucun résultat pour "${esc(q)}"</div>`;
      resultsEl.classList.remove('hidden');
      return;
    }

    resultsEl.innerHTML = results.map(p => {
      const avatar = p.cached_avatar
        ? `<img src="${esc(p.cached_avatar)}" alt="" onerror="this.src=''" />`
        : `<div style="width:36px;height:36px;border-radius:50%;background:var(--gradient);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;">${(p.cached_name||p.slug||'?')[0].toUpperCase()}</div>`;

      return `
        <a class="search-result-item" href="/p/${esc(p.slug)}">
          ${avatar}
          <div>
            <div class="search-result-name">${esc(p.cached_name || p.slug)}</div>
            <div class="search-result-meta">@${esc(p.slug)} ${p.cached_nip05 ? '· ' + esc(p.cached_nip05) : ''}</div>
          </div>
        </a>`;
    }).join('');

    resultsEl.classList.remove('hidden');
  } catch (e) {
    resultsEl.classList.add('hidden');
  }
}

// ─── Toast notifications ──────────────────────────────────────────────────────

function toast(message, type = 'info', duration = 3500) {
  const container = document.getElementById('toast-container');
  if (!container) return;

  const icons = { success: '✅', error: '❌', info: 'ℹ️', warning: '⚠️' };
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.innerHTML = `<span>${icons[type] || ''}</span> ${esc(message)}`;

  container.appendChild(el);
  setTimeout(() => {
    el.style.opacity = '0';
    el.style.transform = 'translateX(1rem)';
    el.style.transition = 'all .3s ease';
    setTimeout(() => el.remove(), 300);
  }, duration);
}

// ─── QR modal (accueil) ───────────────────────────────────────────────────────

function openQR(slug, npub) {
  const modal  = document.getElementById('qr-modal');
  const canvas = document.getElementById('qr-canvas');
  if (!modal || !canvas) return;

  let mode = 'url';
  canvas.innerHTML = '';

  document.getElementById('qr-modal-title').textContent = `@${slug}`;
  modal.classList.remove('hidden');

  const { QRCode } = window;
  if (!QRCode) return;

  function makeQR() {
    canvas.innerHTML = '';
    const content = mode === 'url'
      ? `https://nostrmap.fr/p/${slug}`
      : `nostr:${npub}`;
    new QRCode(canvas, {
      text: content, width: 200, height: 200,
      colorDark: '#ffffff', colorLight: '#13131a',
      correctLevel: QRCode.CorrectLevel.M,
    });
  }
  makeQR();

  document.getElementById('qr-mode-url').onclick   = () => { mode='url';   document.getElementById('qr-mode-url').classList.add('active');   document.getElementById('qr-mode-nostr').classList.remove('active');  makeQR(); };
  document.getElementById('qr-mode-nostr').onclick = () => { mode='nostr'; document.getElementById('qr-mode-nostr').classList.add('active'); document.getElementById('qr-mode-url').classList.remove('active');    makeQR(); };
  document.getElementById('qr-download-btn').onclick = () => {
    const cvs = canvas.querySelector('canvas');
    if (!cvs) return;
    const a = document.createElement('a');
    a.download = `nostrmap-${slug}-qr.png`;
    a.href = cvs.toDataURL('image/png');
    a.click();
  };
}

function closeQR() {
  document.getElementById('qr-modal')?.classList.add('hidden');
}

// Exposer globalement pour les boutons inline dans les cards
window._openQR = openQR;
window._app    = { toast };

// ─── Export ───────────────────────────────────────────────────────────────────

export const app = {
  renderCard,
  loadGrid,
  initSearch,
  toast,
  platformIcon,
  platformName,
  openQR,
  esc,
  fmtNum,
  getNostrProfileHref,
  getNostrWebProfileUrl,
  isMobileDevice,
};
