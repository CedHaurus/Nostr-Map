/**
 * auth.js — Nostr Map
 * Authentification NIP-07 + NIP-98.
 * Gère le login, logout, JWT sessionStorage, et l'UI du header.
 */

const TOKEN_KEY = 'nostrmap_token';

// ─── JWT decode (sans vérification, côté client) ─────────────────────────────

function decodeJWT(token) {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    const payload = JSON.parse(atob(parts[1].replace(/-/g,'+').replace(/_/g,'/')));
    if (payload.exp && payload.exp < Math.floor(Date.now() / 1000)) return null;
    return payload;
  } catch {
    return null;
  }
}

// ─── Hash SHA-256 via Web Crypto ──────────────────────────────────────────────

async function sha256(message) {
  const bytes  = new TextEncoder().encode(message);
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return Array.from(new Uint8Array(digest))
    .map(b => b.toString(16).padStart(2, '0')).join('');
}

// ─── Calcul de l'ID d'un event Nostr ─────────────────────────────────────────

async function computeEventId(event) {
  const serialized = JSON.stringify([
    0,
    event.pubkey,
    event.created_at,
    event.kind,
    event.tags,
    event.content,
  ]);
  return sha256(serialized);
}

// ─── Login NIP-07 + NIP-98 ───────────────────────────────────────────────────

async function login() {
  if (!window.nostr) {
    // Si déjà sur la page d'aide, juste signaler via event
    if (location.pathname.startsWith('/connexion')) {
      window.dispatchEvent(new CustomEvent('nostr:no-extension'));
    } else {
      location.href = '/connexion.html?next=' + encodeURIComponent(location.pathname + location.search);
    }
    return false;
  }

  try {
    // 1. Obtenir la clé publique
    const pubkey = await window.nostr.getPublicKey();
    if (!pubkey) throw new Error('Clé publique non obtenue');

    // 2. Construire l'event NIP-98 (kind:27235)
    const authUrl = `${location.origin}/api/auth.php`;
    const event = {
      kind:       27235,
      created_at: Math.floor(Date.now() / 1000),
      tags: [
        ['u', authUrl],
        ['method', 'POST'],
      ],
      content: '',
      pubkey,
    };

    // 3. Calculer l'ID
    event.id = await computeEventId(event);

    // 4. Faire signer l'event par l'extension
    const signedEvent = await window.nostr.signEvent(event);
    if (!signedEvent?.sig) throw new Error('Signature échouée');

    // 5. Envoyer au serveur
    const res = await fetch('/api/auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ event: signedEvent }),
    });

    const data = await res.json();
    if (!res.ok || data.error) {
      throw new Error(data.error || `Erreur HTTP ${res.status}`);
    }

    // 6. Stocker le JWT
    sessionStorage.setItem(TOKEN_KEY, data.token);

    return data;
  } catch (err) {
    console.error('[auth] login error:', err);
    if (window._app?.toast) window._app.toast(err.message || 'Erreur de connexion', 'error');
    return false;
  }
}

// ─── Logout ───────────────────────────────────────────────────────────────────

function logout() {
  sessionStorage.removeItem(TOKEN_KEY);
}

// ─── Getters ──────────────────────────────────────────────────────────────────

function getToken() {
  const token = sessionStorage.getItem(TOKEN_KEY);
  if (!token) return null;
  if (!decodeJWT(token)) {
    sessionStorage.removeItem(TOKEN_KEY);
    return null;
  }
  return token;
}

function getDecoded() {
  const token = getToken();
  return token ? decodeJWT(token) : null;
}

function isLoggedIn() {
  return getToken() !== null;
}

function setToken(token) {
  sessionStorage.setItem(TOKEN_KEY, token);
}

// ─── Init UI header ───────────────────────────────────────────────────────────
// opts: { loginBtn, logoutBtn, userMenu, slugEl }

function initUI(opts = {}) {
  const { loginBtn, logoutBtn, userMenu, slugEl } = opts;
  // Boutons du menu mobile (IDs fixes, présents sur toutes les pages)
  const mobileLoginBtn  = document.getElementById('mobile-btn-login');
  const mobileLogoutBtn = document.getElementById('mobile-btn-logout');

  function update() {
    const decoded = getDecoded();
    document.body.classList.toggle('is-logged-in', !!decoded);
    // Masquer/afficher tous les boutons "Ajouter mon profil" (header-auth-out)
    document.querySelectorAll('.header-auth-out').forEach(el => {
      el.style.display = decoded ? 'none' : '';
    });
    if (decoded) {
      loginBtn?.classList.add('hidden');
      userMenu?.classList.remove('hidden');
      if (slugEl) slugEl.textContent = '@' + decoded.slug;
    } else {
      loginBtn?.classList.remove('hidden');
      userMenu?.classList.add('hidden');
    }
  }

  const loginSVG = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg>`;

  async function doLogin(btn) {
    btn.disabled = true;
    const orig = btn.innerHTML;
    btn.innerHTML = '<span class="spinner"></span> Connexion…';
    const result = await login();
    if (result) {
      update();
      document.getElementById('mobile-nav')?.classList.remove('open');
      document.getElementById('hamburger')?.classList.remove('open');
      document.body.style.overflow = '';
      if (window._app?.toast) window._app.toast('Connecté ! Bienvenue 🎉', 'success');
    }
    btn.disabled = false;
    btn.innerHTML = orig;
  }

  loginBtn?.addEventListener('click', () => doLogin(loginBtn));
  mobileLoginBtn?.addEventListener('click', () => doLogin(mobileLoginBtn));

  function doLogout() {
    logout();
    update();
    document.getElementById('mobile-nav')?.classList.remove('open');
    document.getElementById('hamburger')?.classList.remove('open');
    document.body.style.overflow = '';
    if (window._app?.toast) window._app.toast('Déconnecté.', 'info');
  }

  logoutBtn?.addEventListener('click', doLogout);
  mobileLogoutBtn?.addEventListener('click', doLogout);

  update();
}

// ─── Login NIP-46 (mobile / signature distante) ──────────────────────────────
// opts.onURI(uri)     : appelé immédiatement avec l'URI nostrconnect://
// opts.onStatus(msg)  : appelé avec des messages de statut
// opts.onError(msg)   : appelé en cas d'échec

async function loginNIP46({ onURI, onStatus, onError } = {}) {
  try {
    const { startNIP46Login } = await import('/assets/js/nip46.js');
    const signedEvent = await startNIP46Login({ onURI, onStatus });

    const res  = await fetch('/api/auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ event: signedEvent }),
    });
    const data = await res.json();

    if (!res.ok || data.error) throw new Error(data.error || `Erreur HTTP ${res.status}`);

    sessionStorage.setItem(TOKEN_KEY, data.token);
    return data;
  } catch (err) {
    console.error('[auth] NIP-46 login error:', err);
    onError?.(err.message || 'Erreur de connexion NIP-46');
    return false;
  }
}

// ─── Export ───────────────────────────────────────────────────────────────────

export const auth = {
  login,
  loginNIP46,
  logout,
  getToken,
  setToken,
  getDecoded,
  isLoggedIn,
  initUI,
};
