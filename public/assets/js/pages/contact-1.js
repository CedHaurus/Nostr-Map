import { app }  from '/assets/js/app.js?v=20261106-csp';
import { auth } from '/assets/js/auth.js?v=20260423-modal';



const token = auth.getToken();

auth.initUI({
  loginBtn:  document.getElementById('btn-login'),
  logoutBtn: document.getElementById('btn-logout'),
  userMenu:  document.getElementById('user-menu'),
  slugEl:    document.getElementById('user-slug-display'),
});

// ── Gestion connexion/déconnexion ─────────────────────────────────
document.getElementById('btn-logout-inline')?.addEventListener('click', () => {
  auth.logout();
  location.reload();
});

// ── Affichage conditionnel ────────────────────────────────────────
if (!token) {
  document.getElementById('auth-gate-desktop').style.display = 'block';
  document.getElementById('btn-login-gate')?.addEventListener('click', async () => {
    const ok = await auth.login();
    if (ok) location.reload();
  });
} else {
  // Connecté sur desktop → afficher le formulaire
  document.getElementById('contact-form-wrap').style.display = 'block';

  // Remplir les infos utilisateur
  const decoded = auth.getDecoded();
  const npubVal = decoded?.sub || '';
  const slug    = decoded?.slug || '';

  document.getElementById('user-npub').textContent = npubVal;

  if (slug) {
    try {
      const res  = await fetch(`/api/profile.php?slug=${encodeURIComponent(slug)}`);
      const data = await res.json();
      if (data.profile) {
        const name   = data.profile.cached_name || slug;
        const avatar = data.profile.cached_avatar || '';
        document.getElementById('user-name').textContent = name;
        if (avatar) {
          const img = document.getElementById('user-avatar');
          img.src = avatar;
          img.style.display = 'block';
          document.getElementById('user-avatar-placeholder').style.display = 'none';
        } else {
          document.getElementById('user-avatar-placeholder').textContent = name.charAt(0).toUpperCase() || '?';
        }
      }
    } catch (e) {
      document.getElementById('user-name').textContent = slug;
      document.getElementById('user-avatar-placeholder').textContent = slug.charAt(0).toUpperCase() || '?';
    }
  }

  // Compteur de caractères
  const textarea = document.getElementById('message');
  const counter  = document.getElementById('char-count');
  textarea.addEventListener('input', () => {
    counter.textContent = textarea.value.length;
  });

  // Soumission du formulaire
  document.getElementById('contact-form').addEventListener('submit', async (e) => {
    e.preventDefault();
    const motif   = document.getElementById('motif').value;
    const message = textarea.value.trim();
    const btn     = document.getElementById('btn-submit');

    if (!motif) { app.toast('Choisissez un motif.', 'warning'); return; }
    if (message.length < 10) { app.toast('Message trop court.', 'warning'); return; }

    btn.disabled = true;
    btn.textContent = 'Envoi en cours…';

    try {
      const t = auth.getToken();
      const res = await fetch('/api/contact.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Authorization': `Bearer ${t}`,
        },
        body: JSON.stringify({ motif, message }),
      });
      const data = await res.json();
      if (!res.ok) {
        app.toast(data.error || 'Erreur lors de l\'envoi.', 'error');
        btn.disabled = false;
        btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Envoyer le message';
      } else {
        document.getElementById('contact-form').style.display = 'none';
        document.getElementById('contact-success').style.display = 'block';
      }
    } catch (err) {
      app.toast('Erreur réseau. Réessayez.', 'error');
      btn.disabled = false;
      btn.innerHTML = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg> Envoyer le message';
    }
  });
}
