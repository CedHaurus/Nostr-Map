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

  initSupportUI();
});

const SUPPORT_CONFIG = {
  lnurlEndpoint: '/.well-known/lnurlp/nostrmap',
  lightningAddress: 'nostrmap@nostrmap.fr',
  recipientNpub: 'npub1n2878xq8jmacnjsyun6a0nrys7tcglzq8znzv05s33ddrxupd36q6uhtpg',
  presetSats: [21, 210, 2100, 21000],
  relays: [
    'wss://relay.nostrmap.net',
    'wss://relay.damus.io',
    'wss://nos.lol',
    'wss://relay.primal.net',
  ],
  qrLibUrl: 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
};

let _supportLnurl = null;
let _supportQrLibPromise = null;
const _supportState = {
  amountSats: 210,
  invoice: '',
  invoiceKind: 'lightning',
};

function initSupportUI() {
  if (document.body?.dataset?.nmSupportReady === '1') return;

  const path = window.location.pathname || '/';
  if (
    /^\/admin(?:\/|$)/.test(path) ||
    /^\/(?:connexion|mon-profil|maintenance)(?:\.html)?$/.test(path)
  ) return;

  document.body.dataset.nmSupportReady = '1';

  injectSupportModal();
  injectSupportFloatingButton();
  injectSupportMenuLink();
  injectSupportFooterLink();
  bindSupportUI();
  syncSupportFloatingButton();
  observeSupportVisibility();
}

function injectSupportFloatingButton() {
  if (document.getElementById('nm-support-floating')) return;

  const button = document.createElement('button');
  button.id = 'nm-support-floating';
  button.className = 'nm-support-floating';
  button.type = 'button';
  button.setAttribute('aria-label', 'Soutenir Nostr Map');
  button.innerHTML = `
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" aria-hidden="true">
      <path d="M13 2 3 14h7l-1 8 10-12h-7z"/>
    </svg>
    <span>Soutenir</span>
  `;
  document.body.appendChild(button);
}

function injectSupportMenuLink() {
  const mobileNavPanel = document.querySelector('.mobile-nav-panel');
  if (!mobileNavPanel || mobileNavPanel.querySelector('.nm-support-menu-link')) return;

  const supportLink = document.createElement('a');
  supportLink.href = '#soutenir';
  supportLink.className = 'mobile-nav-item nm-support-menu-link';
  supportLink.innerHTML = `
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" aria-hidden="true">
      <path d="M13 2 3 14h7l-1 8 10-12h-7z"/>
    </svg>
    Soutenir Nostr Map
  `;

  const firstSecondary = mobileNavPanel.querySelector('.mobile-nav-secondary');
  if (firstSecondary) {
    mobileNavPanel.insertBefore(supportLink, firstSecondary);
  } else {
    mobileNavPanel.appendChild(supportLink);
  }
}

function injectSupportFooterLink() {
  const footerLine = document.querySelector('.footer p:last-of-type');
  if (!footerLine || footerLine.querySelector('.nm-support-footer-link')) return;

  const sep = document.createElement('span');
  sep.className = 'footer-sep nm-support-footer-sep';
  sep.textContent = '|';

  const link = document.createElement('a');
  link.href = '#soutenir';
  link.className = 'nm-support-footer-link';
  link.textContent = 'Soutenir Nostr Map';

  footerLine.appendChild(document.createTextNode(' '));
  footerLine.appendChild(sep);
  footerLine.appendChild(document.createTextNode(' '));
  footerLine.appendChild(link);
}

function injectSupportModal() {
  if (document.getElementById('nm-support-modal')) return;

  const modal = document.createElement('div');
  modal.id = 'nm-support-modal';
  modal.className = 'modal-overlay hidden';
  modal.setAttribute('aria-hidden', 'true');
  modal.innerHTML = `
    <div class="modal nm-support-modal" role="dialog" aria-modal="true" aria-labelledby="nm-support-title">
      <div class="nm-support-drag" aria-hidden="true"></div>

      <div class="nm-support-header">
        <div>
          <div class="nm-support-kicker">Soutenir le projet</div>
          <h3 class="nm-support-title-h3" id="nm-support-title">Soutenir Nostr Map</h3>
        </div>
        <button class="nm-support-close-btn" id="nm-support-close" aria-label="Fermer">✕</button>
      </div>

      <div class="nm-support-body">
        <p class="nm-support-intro">
          Les paiements vont au wallet du projet via
          <strong>${SUPPORT_CONFIG.lightningAddress}</strong>.
        </p>

        <div class="nm-support-amounts" id="nm-support-amounts">
          ${SUPPORT_CONFIG.presetSats.map((amount) => `
            <button class="nm-support-chip${amount === _supportState.amountSats ? ' active' : ''}" type="button" data-sats="${amount}">
              ${formatSats(amount)}
            </button>
          `).join('')}
        </div>

        <label class="nm-support-field" for="nm-support-custom-amount">
          <span>Montant libre</span>
          <input
            id="nm-support-custom-amount"
            class="input"
            type="number"
            min="1"
            step="1"
            inputmode="numeric"
            placeholder="Autre montant en sats"
          />
        </label>

        <label class="nm-support-field" for="nm-support-comment">
          <span>Message optionnel</span>
          <textarea
            id="nm-support-comment"
            class="input nm-support-textarea"
            rows="2"
            maxlength="220"
            placeholder="Visible dans le zap si tu passes par Nostr"
          ></textarea>
        </label>

        <div class="nm-support-actions">
          <button id="nm-support-zap-btn" class="btn btn-primary" type="button">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" aria-hidden="true">
              <path d="M13 2 3 14h7l-1 8 10-12h-7z"/>
            </svg>
            Préparer un zap public
          </button>
          <button id="nm-support-invoice-btn" class="btn btn-outline" type="button">
            Générer une facture
          </button>
          <button id="nm-support-copy-address" class="btn btn-ghost" type="button">
            Copier l'adresse Lightning
          </button>
        </div>

        <p class="nm-support-hint" id="nm-support-zap-hint">
          Un zap public publie un reçu Nostr après paiement.
        </p>
        <div id="nm-support-status" class="nm-support-status hidden" aria-live="polite"></div>

        <div id="nm-support-result" class="nm-support-result hidden">
          <div class="qr-container nm-support-qr-wrap">
            <div id="nm-support-qr"></div>
          </div>
          <div class="nm-support-result-title" id="nm-support-result-title">Facture Lightning</div>
          <div class="nm-support-result-subtitle" id="nm-support-result-subtitle">
            Ouvre ton wallet préféré pour finaliser le paiement.
          </div>
          <code id="nm-support-invoice-code" class="nm-support-invoice-code"></code>
          <div class="nm-support-result-actions">
            <button id="nm-support-open-wallet" class="btn btn-primary" type="button" disabled>
              Ouvrir le wallet
            </button>
            <button id="nm-support-copy-invoice" class="btn btn-ghost" type="button" disabled>
              Copier la facture
            </button>
          </div>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(modal);
}

function bindSupportUI() {
  document.getElementById('nm-support-floating')?.addEventListener('click', openSupport);
  document.querySelector('.nm-support-menu-link')?.addEventListener('click', (event) => {
    event.preventDefault();
    openSupport();
  });
  document.querySelectorAll('.nm-support-footer-link').forEach((link) => {
    link.addEventListener('click', (event) => {
      event.preventDefault();
      openSupport();
    });
  });

  document.getElementById('nm-support-close')?.addEventListener('click', closeSupport);
  document.getElementById('nm-support-modal')?.addEventListener('click', (event) => {
    if (event.target?.id === 'nm-support-modal') closeSupport();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !document.getElementById('nm-support-modal')?.classList.contains('hidden')) {
      closeSupport();
    }
  });

  document.querySelectorAll('#nm-support-amounts .nm-support-chip').forEach((chip) => {
    chip.addEventListener('click', () => {
      const amount = Number(chip.dataset.sats || '0');
      if (!amount) return;
      _supportState.amountSats = amount;
      const customInput = document.getElementById('nm-support-custom-amount');
      if (customInput) customInput.value = '';
      renderSupportAmountSelection();
    });
  });

  document.getElementById('nm-support-custom-amount')?.addEventListener('input', () => {
    renderSupportAmountSelection();
  });

  document.getElementById('nm-support-zap-btn')?.addEventListener('click', async () => {
    await prepareSupportInvoice({ publicZap: true });
  });
  document.getElementById('nm-support-invoice-btn')?.addEventListener('click', async () => {
    await prepareSupportInvoice({ publicZap: false });
  });
  document.getElementById('nm-support-copy-address')?.addEventListener('click', async () => {
    const ok = await copyToClipboard(SUPPORT_CONFIG.lightningAddress);
    toast(ok ? 'Adresse Lightning copiée.' : 'Copie impossible.', ok ? 'success' : 'error');
  });
  document.getElementById('nm-support-copy-invoice')?.addEventListener('click', async () => {
    if (!_supportState.invoice) return;
    const ok = await copyToClipboard(_supportState.invoice);
    toast(ok ? 'Facture copiée.' : 'Copie impossible.', ok ? 'success' : 'error');
  });
  document.getElementById('nm-support-open-wallet')?.addEventListener('click', () => {
    if (!_supportState.invoice) return;
    window.location.href = `lightning:${_supportState.invoice}`;
  });

  renderSupportAmountSelection();
  updateSupportZapAvailability();
}

function openSupport() {
  const mobileNav = document.getElementById('mobile-nav');
  const hamburger = document.getElementById('hamburger');
  mobileNav?.classList.remove('open');
  hamburger?.classList.remove('open');
  hamburger?.setAttribute('aria-expanded', 'false');

  document.getElementById('nm-support-modal')?.classList.remove('hidden');
  document.body.classList.add('nm-support-modal-open');
  document.body.style.overflow = 'hidden';
  syncSupportFloatingButton();
  updateSupportZapAvailability();
}

function closeSupport() {
  document.getElementById('nm-support-modal')?.classList.add('hidden');
  document.body.classList.remove('nm-support-modal-open');
  document.body.style.overflow = '';
  syncSupportFloatingButton();
}

function renderSupportAmountSelection() {
  const customInput = document.getElementById('nm-support-custom-amount');
  const customValue = Number(customInput?.value || '0');
  const effectiveAmount = customValue > 0 ? customValue : _supportState.amountSats;

  document.querySelectorAll('#nm-support-amounts .nm-support-chip').forEach((chip) => {
    const chipAmount = Number(chip.dataset.sats || '0');
    chip.classList.toggle('active', customValue <= 0 && chipAmount === _supportState.amountSats);
  });

  const invoiceBtn = document.getElementById('nm-support-invoice-btn');
  const zapBtn = document.getElementById('nm-support-zap-btn');
  const disabled = !(effectiveAmount > 0);
  invoiceBtn?.toggleAttribute('disabled', disabled);
  zapBtn?.toggleAttribute('disabled', disabled);
}

function updateSupportZapAvailability() {
  const zapBtn = document.getElementById('nm-support-zap-btn');
  const hint = document.getElementById('nm-support-zap-hint');
  const available = hasPublicZapSupport();

  if (zapBtn) {
    zapBtn.disabled = !available;
    zapBtn.title = available ? '' : 'Extension Nostr requise pour un zap public';
  }
  if (hint) {
    hint.textContent = available
      ? 'Un zap public publie un reçu Nostr après paiement.'
      : 'Sans extension Nostr, utilise la facture Lightning classique.';
  }
}

function syncSupportFloatingButton() {
  const floating = document.getElementById('nm-support-floating');
  if (!floating) return;

  const isCompact = window.matchMedia('(max-width: 1024px)').matches;
  const otherModalOpen = !!document.querySelector('.modal-overlay:not(.hidden):not(#nm-support-modal)');
  const mobileMenuOpen = !!document.querySelector('.mobile-nav.open');
  const supportOpen = document.body.classList.contains('nm-support-modal-open');
  const hidden = isCompact || otherModalOpen || mobileMenuOpen || supportOpen;

  floating.classList.toggle('nm-support-floating-hidden', hidden);
}

function observeSupportVisibility() {
  window.addEventListener('resize', syncSupportFloatingButton, { passive: true });

  const observer = new MutationObserver(() => {
    syncSupportFloatingButton();
  });
  observer.observe(document.body, {
    subtree: true,
    attributes: true,
    attributeFilter: ['class'],
  });
}

async function prepareSupportInvoice({ publicZap }) {
  const status = document.getElementById('nm-support-status');
  const result = document.getElementById('nm-support-result');
  const amount = getSupportAmountSats();
  const comment = (document.getElementById('nm-support-comment')?.value || '').trim();
  if (!amount) {
    setSupportStatus('Choisis un montant en sats.', 'error');
    return;
  }

  _supportState.invoice = '';
  setSupportBusy(true);
  result?.classList.add('hidden');
  setSupportStatus(
    publicZap ? 'Préparation du zap public…' : 'Génération de la facture…',
    'loading'
  );

  try {
    const payload = await requestSupportInvoice(amount, { publicZap, comment });
    _supportState.invoice = payload.invoice;
    _supportState.invoiceKind = publicZap ? 'zap' : 'lightning';
    populateSupportResult(amount, publicZap, payload.invoice);
    setSupportStatus(
      publicZap
        ? 'Zap prêt. Il reste à payer la facture dans ton wallet.'
        : 'Facture prête. Tu peux payer avec ton wallet Lightning.',
      'success'
    );
  } catch (error) {
    console.error('[support] invoice error:', error);
    setSupportStatus(error.message || 'Impossible de preparer le paiement.', 'error');
  } finally {
    setSupportBusy(false);
  }
}

function setSupportBusy(busy) {
  const hasZap = hasPublicZapSupport();
  ['nm-support-zap-btn', 'nm-support-invoice-btn', 'nm-support-copy-address', 'nm-support-open-wallet', 'nm-support-copy-invoice']
    .map((id) => document.getElementById(id))
    .forEach((el) => {
      if (!el) return;
      if (el.id === 'nm-support-zap-btn') {
        el.disabled = busy || !hasZap;
      } else if (el.id === 'nm-support-invoice-btn') {
        el.disabled = busy || !getSupportAmountSats();
      } else if (el.id === 'nm-support-open-wallet' || el.id === 'nm-support-copy-invoice') {
        el.disabled = busy || !_supportState.invoice;
      } else {
        el.disabled = busy && (el.id === 'nm-support-copy-address');
      }
    });
}

function setSupportStatus(message, type = 'info') {
  const status = document.getElementById('nm-support-status');
  if (!status) return;

  status.textContent = message;
  status.className = `nm-support-status ${type}`;
  if (!message) status.classList.add('hidden');
}

function populateSupportResult(amountSats, publicZap, invoice) {
  const result = document.getElementById('nm-support-result');
  const title = document.getElementById('nm-support-result-title');
  const subtitle = document.getElementById('nm-support-result-subtitle');
  const invoiceCode = document.getElementById('nm-support-invoice-code');

  if (title) title.textContent = publicZap ? 'Zap public pret' : 'Facture Lightning';
  if (subtitle) {
    subtitle.textContent = publicZap
      ? `${formatSats(amountSats)} a payer pour publier le zap.`
      : `${formatSats(amountSats)} a payer avec ton wallet Lightning.`;
  }
  if (invoiceCode) invoiceCode.textContent = invoice;

  result?.classList.remove('hidden');
  renderSupportQR(invoice);
  // Scroll au résultat (dans le body scrollable du modal)
  setTimeout(() => {
    const body = document.querySelector('.nm-support-body');
    if (body) body.scrollTop = body.scrollHeight;
  }, 80);
}

function getSupportAmountSats() {
  const customValue = Number(document.getElementById('nm-support-custom-amount')?.value || '0');
  return customValue > 0 ? Math.floor(customValue) : _supportState.amountSats;
}

async function requestSupportInvoice(amountSats, { publicZap, comment }) {
  const lnurl = await getSupportLnurl();
  const amountMsat = amountSats * 1000;
  const callbackUrl = new URL(lnurl.callback, window.location.origin);
  callbackUrl.searchParams.set('amount', String(amountMsat));

  if (publicZap) {
    if (!window.nostr?.signEvent || !window.nostr?.getPublicKey) {
      throw new Error('Extension Nostr requise pour un zap public.');
    }
    const signedZap = await createSignedZapRequest(amountMsat, comment || '');
    callbackUrl.searchParams.set('nostr', JSON.stringify(signedZap));
  }

  const response = await fetch(callbackUrl.toString(), { cache: 'no-store' });
  const data = await response.json();
  if (!response.ok || data?.status === 'ERROR' || !data?.pr) {
    throw new Error(data?.reason || 'Le wallet du projet n\'a pas renvoye de facture.');
  }
  return { invoice: data.pr };
}

async function getSupportLnurl() {
  if (_supportLnurl) return _supportLnurl;

  const response = await fetch(SUPPORT_CONFIG.lnurlEndpoint, { cache: 'no-store' });
  const data = await response.json();
  if (!response.ok || !data?.callback) {
    throw new Error('Impossible de charger le point de paiement.');
  }
  _supportLnurl = data;
  return data;
}

async function createSignedZapRequest(amountMsat, comment) {
  const pubkey = await window.nostr.getPublicKey();
  const event = {
    kind: 9734,
    created_at: Math.floor(Date.now() / 1000),
    content: comment,
    tags: [
      ['relays', ...SUPPORT_CONFIG.relays],
      ['amount', String(amountMsat)],
      ['p', decodeNpubToHex(SUPPORT_CONFIG.recipientNpub)],
    ],
    pubkey,
  };

  return window.nostr.signEvent(event);
}

function decodeNpubToHex(value) {
  const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';
  const lower = String(value || '').toLowerCase().trim();
  const pos = lower.lastIndexOf('1');
  if (pos < 1) throw new Error('Npub invalide');
  const data = Array.from(lower.slice(pos + 1, -6)).map((char) => {
    const idx = CHARSET.indexOf(char);
    if (idx < 0) throw new Error('Caractere bech32 invalide');
    return idx;
  });
  let acc = 0;
  let bits = 0;
  const bytes = [];
  for (const value5 of data) {
    acc = (acc << 5) | value5;
    bits += 5;
    while (bits >= 8) {
      bits -= 8;
      bytes.push((acc >> bits) & 0xff);
    }
  }
  return bytes.map((byte) => byte.toString(16).padStart(2, '0')).join('');
}

function formatSats(amount) {
  return `${new Intl.NumberFormat('fr-FR').format(amount)} sats`;
}

async function renderSupportQR(text) {
  const target = document.getElementById('nm-support-qr');
  if (!target) return;
  target.innerHTML = '';

  try {
    const QRCode = await loadSupportQrLib();
    new QRCode(target, {
      text,
      width: 188,
      height: 188,
      colorDark: '#ffffff',
      colorLight: '#13131a',
      correctLevel: QRCode.CorrectLevel.M,
    });
  } catch {
    target.innerHTML = '<div class="nm-support-qr-fallback">QR indisponible ici</div>';
  }
}

function loadSupportQrLib() {
  if (window.QRCode) return Promise.resolve(window.QRCode);
  if (_supportQrLibPromise) return _supportQrLibPromise;

  _supportQrLibPromise = new Promise((resolve, reject) => {
    const existing = document.querySelector(`script[src="${SUPPORT_CONFIG.qrLibUrl}"]`);
    if (existing) {
      if (window.QRCode) {
        resolve(window.QRCode);
        return;
      }
      existing.addEventListener('load', () => resolve(window.QRCode), { once: true });
      existing.addEventListener('error', reject, { once: true });
      return;
    }

    const script = document.createElement('script');
    script.src = SUPPORT_CONFIG.qrLibUrl;
    script.async = true;
    script.onload = () => resolve(window.QRCode);
    script.onerror = reject;
    document.head.appendChild(script);
  });

  return _supportQrLibPromise;
}

function hasPublicZapSupport() {
  return !!(window.nostr && typeof window.nostr.getPublicKey === 'function' && typeof window.nostr.signEvent === 'function');
}

async function copyToClipboard(value) {
  try {
    await navigator.clipboard.writeText(value);
    return true;
  } catch {
    try {
      const probe = document.createElement('textarea');
      probe.value = value;
      probe.setAttribute('readonly', '');
      probe.style.position = 'absolute';
      probe.style.left = '-9999px';
      document.body.appendChild(probe);
      probe.select();
      document.execCommand('copy');
      probe.remove();
      return true;
    } catch {
      return false;
    }
  }
}

function fmtNum(n) {
  if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0','') + 'M';
  if (n >= 1000)    return (n / 1000).toFixed(1).replace('.0','') + 'k';
  return String(n);
}

// ─── Plateforme helpers ──────────────────────────────────────────────────────

const PLATFORMS = {
  x:        { name: 'X / Twitter',  icon: '𝕏' },
  mastodon: { name: 'Mastodon',     icon: '🐘' },
  bluesky:  { name: 'Bluesky',      icon: '🦋' },
  youtube:  { name: 'YouTube',      icon: '▶️' },
  website:  { name: 'Site internet', icon: '🌐' },
  relay:    { name: 'Relay',         icon: '⚡' },
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

  // Coche verte si au moins un lien vérifié
  const verifiedLinks = links.filter(l => l.verified);
  const verifiedDot = verifiedLinks.length
    ? `<span class="verified-dot" title="Profil vérifié — liens vérifiés">✓</span>`
    : '';

  // Point violet si ajout communautaire (non encore revendiqué par le propriétaire)
  const communityDot = profile.community_added
    ? `<span class="community-dot" title="Ajout communautaire — non revendiqué"></span>`
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
      <a href="/p/${slug}" class="profile-card-body" onclick="event.stopPropagation()">
        <div class="avatar-wrap" id="card-av-${slug}">${avatarHtml}</div>
        <div class="profile-card-info">
          <div class="profile-name-row">
            <div class="profile-name" id="card-name-${slug}">${name}</div>
            ${verifiedDot}
            ${communityDot}
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

// ─── Stats cache (localStorage, TTL 1h) ─────────────────────────────────────
const _STATS_TTL = 60 * 60 * 1000; // 1 heure

function getCachedStats(npub) {
  try {
    const raw = localStorage.getItem('nm_s_' + npub);
    if (!raw) return null;
    const d = JSON.parse(raw);
    if (!d || Date.now() - d.t > _STATS_TTL) return null;
    return d;
  } catch { return null; }
}

function setCachedStats(npub, stats) {
  try {
    localStorage.setItem('nm_s_' + npub, JSON.stringify({
      followers: stats.followers  ?? stats.nostr_followers  ?? 0,
      posts:     stats.posts      ?? stats.nostr_posts      ?? 0,
      createdAt: stats.createdAt  ?? stats.nostr_created_at ?? null,
      t: Date.now(),
    }));
  } catch {}
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
  openSupport,
  platformIcon,
  platformName,
  openQR,
  esc,
  fmtNum,
  getNostrProfileHref,
  getNostrWebProfileUrl,
  isMobileDevice,
  getCachedStats,
  setCachedStats,
};
