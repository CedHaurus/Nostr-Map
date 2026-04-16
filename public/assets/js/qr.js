/**
 * qr.js — Nostr Map
 * Gestion de la modale QR Code de l'accueil (index.html).
 * La lib qrcode.js doit être chargée en CDN avant ce module.
 */

import { app } from '/assets/js/app.js';

let _currentSlug  = null;
let _currentNpub  = null;
let _qrMode       = 'url';
let _qrInstance   = null;

function initQR() {
  const modal    = document.getElementById('qr-modal');
  const closeBtn = document.getElementById('qr-modal-close');
  const closeBtn2 = document.getElementById('qr-modal-close2');
  const canvas   = document.getElementById('qr-canvas');

  if (!modal) return;

  // Fermeture
  closeBtn?.addEventListener('click', closeModal);
  closeBtn2?.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeModal();
  });

  // Touche Escape
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeModal();
  });

  // Toggle URL / nostr
  document.getElementById('qr-mode-url')?.addEventListener('click', function () {
    _qrMode = 'url';
    this.classList.add('active');
    document.getElementById('qr-mode-nostr')?.classList.remove('active');
    rebuildQR(canvas);
  });

  document.getElementById('qr-mode-nostr')?.addEventListener('click', function () {
    _qrMode = 'nostr';
    this.classList.add('active');
    document.getElementById('qr-mode-url')?.classList.remove('active');
    rebuildQR(canvas);
  });

  // Télécharger
  document.getElementById('qr-download-btn')?.addEventListener('click', () => {
    downloadQR(canvas, _currentSlug);
  });

  // Exposer pour les boutons inline des cards
  window._openQR = (slug, npub) => openModal(slug, npub, canvas);
}

function openModal(slug, npub, canvas) {
  _currentSlug = slug;
  _currentNpub = npub;
  _qrMode      = 'url';

  // Réinitialiser les boutons toggle
  document.getElementById('qr-mode-url')?.classList.add('active');
  document.getElementById('qr-mode-nostr')?.classList.remove('active');

  // Titre
  const title = document.getElementById('qr-modal-title');
  if (title) title.textContent = `@${slug}`;

  // Afficher la modale
  const modal = document.getElementById('qr-modal');
  modal?.classList.remove('hidden');
  document.body.style.overflow = 'hidden';

  // Générer le QR
  rebuildQR(canvas || document.getElementById('qr-canvas'));
}

function closeModal() {
  document.getElementById('qr-modal')?.classList.add('hidden');
  document.body.style.overflow = '';
  _qrInstance  = null;
  _currentSlug = null;
  _currentNpub = null;
}

function rebuildQR(canvas) {
  if (!canvas || !_currentSlug) return;
  canvas.innerHTML = '';

  const { QRCode } = window;
  if (!QRCode) {
    canvas.innerHTML = '<p style="color:var(--text-muted);font-size:.8rem;">QR Code lib non chargée.</p>';
    return;
  }

  const content = _qrMode === 'url'
    ? `https://nostrmap.fr/p/${_currentSlug}`
    : `nostr:${_currentNpub}`;

  _qrInstance = new QRCode(canvas, {
    text:         content,
    width:        200,
    height:       200,
    colorDark:    '#ffffff',
    colorLight:   '#13131a',
    correctLevel: QRCode.CorrectLevel.M,
  });
}

function downloadQR(canvas, slug) {
  if (!canvas) return;
  const cvs = canvas.querySelector('canvas');
  if (!cvs) {
    app.toast('QR Code non disponible.', 'error');
    return;
  }
  const a      = document.createElement('a');
  a.download   = `nostrmap-${slug || 'qr'}-qr.png`;
  a.href       = cvs.toDataURL('image/png');
  a.click();
}

export { initQR };
