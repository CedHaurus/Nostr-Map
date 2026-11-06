import { auth } from '/assets/js/auth.js?v=20260423-modal';
import { app }  from '/assets/js/app.js?v=20261106-csp';
import { nostr } from '/assets/js/nostr.js';

// ─── Auth ─────────────────────────────────────────────────────────────────────

auth.initUI({
  loginBtn:  document.getElementById('btn-login'),
  logoutBtn: document.getElementById('btn-logout'),
  userMenu:  document.getElementById('user-menu'),
  slugEl:    document.getElementById('user-slug-display'),
});

function checkAuth() {
  if (auth.isLoggedIn()) {
    document.getElementById('auth-wall').style.display = 'none';
    document.getElementById('sub-form').style.display  = 'block';
  } else {
    document.getElementById('auth-wall').style.display = 'block';
    document.getElementById('sub-form').style.display  = 'none';
  }
}
checkAuth();

document.getElementById('btn-login-wall').addEventListener('click', async () => {
  const btn = document.getElementById('btn-login-wall');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Connexion…';
  const result = await auth.login();
  if (result) {
    checkAuth();
    app.toast('Connecté !', 'success');
  }
  btn.disabled = false;
  btn.innerHTML = `<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4M10 17l5-5-5-5M15 12H3"/></svg> Se connecter`;
});

// Aussi mettre à jour la vue si connexion via btn header
document.getElementById('btn-login').addEventListener('click', () => {
  setTimeout(checkAuth, 500);
});
document.getElementById('btn-logout').addEventListener('click', () => {
  setTimeout(checkAuth, 100);
});

// ─── Npub input ───────────────────────────────────────────────────────────────

const npubInput   = document.getElementById('npub-input');
const npubError   = document.getElementById('npub-error');
const npubLoading = document.getElementById('npub-loading');
const npubIcon    = document.getElementById('npub-icon');
const preview     = document.getElementById('profile-preview');
const submitBtn   = document.getElementById('submit-btn');

let _npubTimer = null;
let _validNpub = null;       // npub validé et non existant
let _profileData = null;     // données du profil fetché

function npubToHex(npub) {
  const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
  const str = npub.toLowerCase();
  const sep = str.lastIndexOf('1');
  if (sep < 1) throw new Error('Invalid');
  const dataStr = str.slice(sep + 1, -6);
  const data5 = [];
  for (const c of dataStr) {
    const idx = CHARSET.indexOf(c);
    if (idx < 0) throw new Error('Invalid char');
    data5.push(idx);
  }
  let acc = 0, bits = 0;
  const bytes = [];
  for (const v of data5) {
    acc = (acc << 5) | v;
    bits += 5;
    while (bits >= 8) { bits -= 8; bytes.push((acc >> bits) & 0xff); }
  }
  return bytes.map(b => b.toString(16).padStart(2,'0')).join('');
}

function setNpubState(state, msg = '') {
  npubInput.classList.remove('error', 'ok');
  npubError.style.display   = 'none';
  npubLoading.style.display = 'none';
  npubIcon.style.display    = 'none';

  if (state === 'loading') {
    npubLoading.style.display = 'block';
    npubLoading.textContent = msg || 'Vérification…';
  } else if (state === 'error') {
    npubInput.classList.add('error');
    npubError.style.display   = 'block';
    npubError.textContent     = msg;
    npubIcon.style.display    = 'block';
    npubIcon.textContent      = '✗';
    npubIcon.style.color      = 'var(--danger)';
  } else if (state === 'ok') {
    npubInput.classList.add('ok');
    npubIcon.style.display    = 'block';
    npubIcon.textContent      = '✓';
    npubIcon.style.color      = '#22c55e';
  } else {
    // idle
  }
}

function showPreview(data) {
  const name   = data.name || data.display_name || '';
  const nip05  = data.nip05 || '';
  const bio    = data.about || '';
  const avatar = data.picture || '';

  document.getElementById('prev-name').textContent  = name || '(sans nom)';
  document.getElementById('prev-nip05').textContent = nip05 ? nip05 : '';
  document.getElementById('prev-bio').textContent   = bio;
  document.getElementById('prev-nip05').style.display = nip05 ? 'block' : 'none';
  document.getElementById('prev-bio').style.display   = bio ? 'block' : 'none';

  const wrap = document.getElementById('prev-avatar-wrap');
  if (avatar) {
    wrap.innerHTML = `<img class="prev-avatar" src="${app.esc(avatar)}" alt="" data-fallback="hide" />`;
  } else {
    const letter = (name || '?')[0].toUpperCase();
    wrap.innerHTML = `<div class="prev-placeholder">${letter}</div>`;
  }

  preview.style.display = 'flex';
  _profileData = data;
}

async function checkNpub(npub) {
  setNpubState('loading', 'Vérification du profil…');
  _validNpub  = null;
  _profileData = null;
  submitBtn.disabled = true;
  preview.style.display = 'none';
  document.getElementById('links-section').style.display   = 'none';
  document.getElementById('message-section').style.display = 'none';

  // Validation format
  if (!npub || npub.length < 60 || !npub.startsWith('npub1')
      || !/^npub1[qpzry9x8gf2tvdw0s3jn54khce6mua7l]+$/i.test(npub)) {
    setNpubState('error', 'Format npub invalide.');
    return;
  }

  // Vérifier si déjà dans l'annuaire
  try {
    // On utilise search.php pour un lookup rapide par npub
    const r = await fetch('/api/search.php?q=' + encodeURIComponent(npub) + '&limit=1');
    const d = await r.json();
    // Chercher une correspondance exacte sur le npub
    const found = (d.results || []).find(p => p.npub === npub);
    if (found) {
      setNpubState('error', `Ce profil est déjà dans l'annuaire (@${app.esc(found.slug)}).`);
      return;
    }
  } catch {}

  // Vérifier via l'API dédiée (pending proposals)
  // On tente le submit pour avoir un retour précis — non, on vérifie juste le format pour l'instant
  // Fetch du profil Nostr depuis les relais
  setNpubState('loading', 'Récupération du profil Nostr…');
  try {
    let hex;
    try { hex = npubToHex(npub); } catch {
      setNpubState('error', 'npub invalide (erreur de décodage).');
      return;
    }

    const profileData = await new Promise((resolve) => {
      nostr.fetchProfile(npub, (data) => resolve(data));
      setTimeout(() => resolve(null), 8000);
    });

    if (profileData) {
      showPreview(profileData);
    } else {
      // Profil introuvable sur les relais — on accepte quand même
      const letter = npub.slice(5, 6).toUpperCase();
      showPreview({ name: '', display_name: '', about: 'Profil introuvable sur les relais.', picture: '' });
    }

    setNpubState('ok');
    _validNpub = npub;
    submitBtn.disabled = false;
    document.getElementById('links-section').style.display   = 'block';
    document.getElementById('message-section').style.display = 'block';

  } catch (e) {
    setNpubState('error', 'Erreur lors de la récupération du profil.');
  }
}

npubInput.addEventListener('input', () => {
  clearTimeout(_npubTimer);
  const val = npubInput.value.trim();
  if (!val) {
    setNpubState('idle');
    preview.style.display = 'none';
    submitBtn.disabled = true;
    document.getElementById('links-section').style.display   = 'none';
    document.getElementById('message-section').style.display = 'none';
    return;
  }
  _npubTimer = setTimeout(() => checkNpub(val), 600);
});

// ─── Liens sociaux ────────────────────────────────────────────────────────────

const PLATFORMS = {
  x:        'X / Twitter',
  mastodon: 'Mastodon',
  bluesky:  'Bluesky',
  youtube:  'YouTube',
};

window.addLinkRow = function(platform = '', url = '') {
  const list = document.getElementById('links-list');
  if (list.children.length >= 6) {
    app.toast('Maximum 6 liens.', 'warning');
    return;
  }

  const row = document.createElement('div');
  row.className = 'link-row';

  const options = Object.entries(PLATFORMS).map(([v, l]) =>
    `<option value="${v}"${platform === v ? ' selected' : ''}>${l}</option>`
  ).join('');

  row.innerHTML = `
    <select class="link-platform">
      <option value="">Plateforme…</option>
      ${options}
    </select>
    <input type="url" class="link-url" placeholder="https://…" value="${app.esc(url)}" maxlength="500" />
    <button type="button" class="btn-remove-link" title="Supprimer" data-remove-closest=".link-row">✕</button>
  `;
  list.appendChild(row);
};

function getLinks() {
  return Array.from(document.querySelectorAll('.link-row')).map(row => ({
    platform: row.querySelector('.link-platform').value,
    url:      row.querySelector('.link-url').value.trim(),
    handle:   '',
  })).filter(l => l.platform && l.url);
}

// ─── Message counter ──────────────────────────────────────────────────────────

document.getElementById('message-input').addEventListener('input', function() {
  document.getElementById('msg-count').textContent = this.value.length + ' / 1000';
});

// ─── Submit ───────────────────────────────────────────────────────────────────

submitBtn.addEventListener('click', async () => {
  if (!_validNpub) return;

  const token = auth.getToken();
  if (!token) { app.toast('Vous devez être connecté.', 'error'); checkAuth(); return; }

  submitBtn.disabled = true;
  submitBtn.innerHTML = '<span class="spinner"></span> Envoi…';

  const payload = {
    npub:          _validNpub,
    message:       document.getElementById('message-input').value.trim() || null,
    links:         getLinks(),
    cached_name:   _profileData?.name || _profileData?.display_name || null,
    cached_avatar: _profileData?.picture || null,
  };

  try {
    const res  = await fetch('/api/propose.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token },
      body:    JSON.stringify(payload),
    });
    const data = await res.json();

    if (!res.ok || data.error) {
      app.toast(data.error || 'Erreur lors de l\'envoi.', 'error');
      submitBtn.disabled = false;
      submitBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg> Envoyer la proposition`;
      return;
    }

    // Succès
    document.getElementById('form-content').style.display = 'none';
    document.getElementById('success-state').style.display = 'block';

  } catch (e) {
    app.toast('Erreur réseau.', 'error');
    submitBtn.disabled = false;
    submitBtn.innerHTML = `<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg> Envoyer la proposition`;
  }
});

// ─── Reset form ───────────────────────────────────────────────────────────────

window.resetForm = function() {
  npubInput.value = '';
  document.getElementById('links-list').innerHTML = '';
  document.getElementById('message-input').value  = '';
  document.getElementById('msg-count').textContent = '0 / 1000';
  preview.style.display = 'none';
  document.getElementById('links-section').style.display   = 'none';
  document.getElementById('message-section').style.display = 'none';
  document.getElementById('success-state').style.display   = 'none';
  document.getElementById('form-content').style.display    = 'block';
  setNpubState('idle');
  _validNpub  = null;
  _profileData = null;
  submitBtn.disabled = true;
};
