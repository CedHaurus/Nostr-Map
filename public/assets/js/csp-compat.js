/**
 * csp-compat.js — Nostr Map
 *
 * Remplace les gestionnaires d'événements écrits dans le HTML (onclick=,
 * onerror=, onsubmit=…), interdits par la politique de sécurité du contenu
 * (CSP sans 'unsafe-inline'). Les comportements sont décrits par des
 * attributs data-… et traités ici par délégation, y compris pour les
 * éléments ajoutés plus tard par innerHTML.
 *
 * À charger dans le <head>, sans defer ni async : l'écoute des erreurs
 * d'images doit être en place avant l'analyse des balises <img>.
 *
 *   <img data-fallback="hide">                → masque l'image en erreur
 *   <img data-fallback="placeholder">         → masque, affiche l'élément suivant
 *   <img data-fallback="hide" data-fallback-show="id"> → masque, affiche #id
 *   <img data-fallback="clear">               → retire la source
 *   <form data-confirm="Question ?">          → demande confirmation avant envoi
 *   data-action="nomFonction" data-args='["a", "$this"]'
 *                                             → appelle window.nomFonction(…)
 *                                               ("$this" = l'élément cliqué)
 *   data-hide="id"                            → masque #id au clic
 *   data-remove-closest=".sélecteur"          → retire l'ancêtre au clic
 *   data-stop                                 → n'active pas les data-href parents
 *   data-href="/chemin"                       → navigation au clic
 */
(function () {
  'use strict';

  document.addEventListener('error', function (e) {
    var el = e.target;
    if (!el || el.tagName !== 'IMG' || !el.hasAttribute('data-fallback')) return;
    var mode = el.getAttribute('data-fallback');
    if (mode === 'clear') { el.removeAttribute('src'); return; }
    el.style.display = 'none';
    if (mode === 'placeholder' && el.nextElementSibling) {
      el.nextElementSibling.style.display = 'flex';
    }
    var showId = el.getAttribute('data-fallback-show');
    if (showId) {
      var target = document.getElementById(showId);
      if (target) target.style.display = 'flex';
    }
  }, true);

  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (!form || !form.hasAttribute || !form.hasAttribute('data-confirm')) return;
    if (!window.confirm(form.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopImmediatePropagation();
    }
  }, true);

  function parseArgs(node) {
    var raw = node.getAttribute('data-args');
    if (!raw) return [];
    try {
      var args = JSON.parse(raw);
      if (!Array.isArray(args)) args = [args];
      return args.map(function (a) { return a === '$this' ? node : a; });
    } catch (_) {
      return [];
    }
  }

  document.addEventListener('click', function (e) {
    for (var node = e.target; node && node !== document; node = node.parentNode) {
      if (node.nodeType !== 1) continue;

      if (node.hasAttribute('data-action')) {
        var fn = window[node.getAttribute('data-action')];
        if (typeof fn === 'function') fn.apply(node, parseArgs(node));
      }
      if (node.hasAttribute('data-hide')) {
        var hidden = document.getElementById(node.getAttribute('data-hide'));
        if (hidden) hidden.style.display = 'none';
      }
      if (node.hasAttribute('data-remove-closest')) {
        var ancestor = node.closest(node.getAttribute('data-remove-closest'));
        if (ancestor) ancestor.remove();
      }
      if (node.hasAttribute('data-stop')) return;
      if (node.hasAttribute('data-href')) {
        window.location.href = node.getAttribute('data-href');
        return;
      }
    }
  });
})();
