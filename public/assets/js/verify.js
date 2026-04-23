/**
 * verify.js — Nostr Map
 * Vérification des liens RS via l'API /api/verify.php.
 */

import { auth } from '/assets/js/auth.js?v=20260423-modal';
import { app }  from '/assets/js/app.js?v=20260423-modal';

/**
 * Initialise les handlers de vérification.
 * opts : { npub, slug, onUpdate }
 */
function initVerify(opts = {}) {
  const { onUpdate } = opts;

  // Exposer globalement pour les boutons inline générés dans mon-profil.html
  window._verifyLink = async (linkId) => {
    return verifyLink(linkId, onUpdate);
  };
}

/**
 * Lance la vérification d'un lien via le serveur.
 * @param {number} linkId
 * @param {Function} [onUpdate] - callback appelé après vérification
 */
async function verifyLink(linkId, onUpdate) {
  const token = auth.getToken();
  if (!token) {
    app.toast('Vous devez être connecté.', 'error');
    return false;
  }

  try {
    const res  = await fetch('/api/verify.php', {
      method:  'POST',
      headers: {
        'Content-Type':  'application/json',
        'Authorization': 'Bearer ' + token,
      },
      body: JSON.stringify({ link_id: linkId }),
    });

    const data = await res.json();

    if (!res.ok) {
      app.toast(data.error || 'Erreur serveur', 'error');
      return false;
    }

    if (data.verified) {
      app.toast('Lien vérifié ! Vous pouvez retirer le code challenge de votre page.', 'success', 6000);
      onUpdate?.();
      return true;
    } else {
      app.toast(data.message || 'Code challenge introuvable.', 'warning');
      return false;
    }
  } catch (e) {
    app.toast('Erreur réseau lors de la vérification.', 'error');
    return false;
  }
}

export { initVerify, verifyLink };
