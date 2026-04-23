/**
 * auth.js — Nostr Map
 * Authentification NIP-07 + NIP-98.
 * Gère le login, logout, JWT sessionStorage, et l'UI du header.
 */

const TOKEN_KEY = 'nostrmap_token';

// Quand le navigateur restaure une page depuis le back-forward cache,
// on recharge pour éviter d'afficher une version publique incohérente
// après une déconnexion admin qui a retiré le cookie preview.
window.addEventListener('pageshow', (event) => {
  if (event.persisted) {
    window.location.reload();
  }
});

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
  const { loginBtn, logoutBtn, userMenu, slugEl, onLogin, onLogout } = opts;
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
      if (slugEl) {
        slugEl.textContent = '@' + decoded.slug;
        // Remplacer par le nom d'affichage dès qu'on l'a
        fetch(`/api/profile.php?slug=${encodeURIComponent(decoded.slug)}`)
          .then(r => r.json()).then(data => {
            const name = data?.profile?.cached_name;
            if (name) slugEl.textContent = name;
          }).catch(() => {});
      }
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
      onLogin?.(result);
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
    onLogout?.();
    document.getElementById('mobile-nav')?.classList.remove('open');
    document.getElementById('hamburger')?.classList.remove('open');
    document.body.style.overflow = '';
    if (window._app?.toast) window._app.toast('Déconnecté.', 'info');
  }

  logoutBtn?.addEventListener('click', doLogout);
  mobileLogoutBtn?.addEventListener('click', doLogout);

  update();
}

// ─── Bech32 decode (nsec → Uint8Array) ──────────────────────────────────────

function _bech32Decode(str) {
  const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
  const GEN     = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];

  function polymod(vals) {
    let c = 1;
    for (const v of vals) {
      const top = c >> 25;
      c = ((c & 0x1ffffff) << 5) ^ v;
      for (let i = 0; i < 5; i++) if ((top >> i) & 1) c ^= GEN[i];
    }
    return c;
  }

  function hrpExpand(h) {
    const r = [];
    for (let i = 0; i < h.length; i++) r.push(h.charCodeAt(i) >> 5);
    r.push(0);
    for (let i = 0; i < h.length; i++) r.push(h.charCodeAt(i) & 31);
    return r;
  }

  const s   = str.toLowerCase().trim();
  const pos = s.lastIndexOf('1');
  if (pos < 1 || pos + 7 > s.length || s.length > 90) throw new Error('Format de clé invalide');

  const hrp  = s.slice(0, pos);
  const data = [];
  for (const c of s.slice(pos + 1)) {
    const d = CHARSET.indexOf(c);
    if (d < 0) throw new Error('Caractère invalide dans la clé');
    data.push(d);
  }

  if (polymod([...hrpExpand(hrp), ...data]) !== 1)
    throw new Error('Clé nsec invalide — vérifiez que vous avez copié la clé complète');

  let bits = 0, acc = 0;
  const bytes = [];
  for (const d of data.slice(0, -6)) {
    acc  = (acc << 5) | d;
    bits += 5;
    if (bits >= 8) { bytes.push((acc >> (bits - 8)) & 0xff); bits -= 8; }
  }

  return { hrp, bytes: new Uint8Array(bytes) };
}

// ─── Login avec clé nsec (NIP-98, sans extension) ────────────────────────────

async function loginWithNsec(nsecStr) {
  let privKey = null;
  try {
    // 1. Décoder la nsec → clé privée 32 octets
    const { hrp, bytes } = _bech32Decode(nsecStr.trim());
    if (hrp !== 'nsec') throw new Error('La clé doit commencer par nsec1…');
    if (bytes.length !== 32) throw new Error('Longueur de clé invalide (32 octets attendus)');
    privKey = bytes;

    // 2. Charger secp256k1 schnorr (lazy, mis en cache après le premier appel)
    const { schnorr } = await import('https://esm.sh/@noble/curves@1.4.2/secp256k1');

    // 3. Dériver la clé publique x-only (BIP-340)
    const pubkeyBytes = schnorr.getPublicKey(privKey);
    const pubkey = Array.from(pubkeyBytes).map(b => b.toString(16).padStart(2, '0')).join('');

    // 4. Construire l'event NIP-98 (kind:27235)
    const authUrl = `${location.origin}/api/auth.php`;
    const event = {
      kind:       27235,
      created_at: Math.floor(Date.now() / 1000),
      tags:       [['u', authUrl], ['method', 'POST']],
      content:    '',
      pubkey,
    };

    // 5. Calculer l'ID (SHA-256 de la sérialisation)
    event.id = await computeEventId(event);

    // 6. Signer avec Schnorr — privKey utilisée ici uniquement
    const idBytes  = new Uint8Array(event.id.match(/.{2}/g).map(h => parseInt(h, 16)));
    const sigBytes = schnorr.sign(idBytes, privKey);
    event.sig = Array.from(sigBytes).map(b => b.toString(16).padStart(2, '0')).join('');

    // 7. Effacer la clé privée de la mémoire immédiatement
    privKey.fill(0);
    privKey = null;

    // 8. Envoyer l'event signé au serveur
    const res  = await fetch('/api/auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ event }),
    });
    const data = await res.json();
    if (!res.ok || data.error) throw new Error(data.error || `Erreur HTTP ${res.status}`);

    sessionStorage.setItem(TOKEN_KEY, data.token);
    return data;

  } catch (err) {
    if (privKey) { privKey.fill(0); privKey = null; }
    console.error('[auth] loginWithNsec error:', err);
    if (window._app?.toast) window._app.toast(err.message || 'Erreur de connexion', 'error');
    return false;
  }
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
  loginWithNsec,
  loginNIP46,
  logout,
  getToken,
  setToken,
  getDecoded,
  isLoggedIn,
  initUI,
};
