function toggle(el) {
  const item = el.closest('.faq-item');
  const isOpen = item.classList.contains('open');
  document.querySelectorAll('.faq-item.open').forEach(i => i.classList.remove('open'));
  if (!isOpen) item.classList.add('open');
}

const hash = location.hash.slice(1);
if (hash) {
  const items = document.querySelectorAll('.faq-item');
  items.forEach((item, i) => { if (String(i + 1) === hash) item.classList.add('open'); });
}

// Fetch live GitHub stats
(async function () {
  function relativeDate(iso) {
    const d = new Date(iso), now = new Date();
    const days = Math.round((now - d) / 864e5);
    if (days === 0) return 'aujourd\'hui';
    if (days === 1) return 'hier';
    if (days < 7)  return `il y a ${days} j`;
    if (days < 30) return `il y a ${Math.round(days / 7)} sem.`;
    if (days < 365) return `il y a ${Math.round(days / 30)} mois`;
    return `il y a ${Math.round(days / 365)} an${Math.round(days / 365) > 1 ? 's' : ''}`;
  }

  async function fillCard(repo, starsId, updatedId, descId) {
    try {
      const r    = await fetch(`https://api.github.com/repos/${repo}`);
      const data = await r.json();
      if (data.stargazers_count !== undefined) {
        document.getElementById(starsId).innerHTML =
          `<svg width="12" height="12" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> ${data.stargazers_count}`;
        document.getElementById(updatedId).textContent = 'Mis à jour ' + relativeDate(data.pushed_at);
        if (descId && data.description) document.getElementById(descId).textContent = data.description;
      }
    } catch {}
  }

  fillCard('CedHaurus/Nostr-map-signer', 'gh-signer-stars', 'gh-signer-updated', 'gh-signer-desc');
  fillCard('CedHaurus/Nostr-Map',        'gh-map-stars',    'gh-map-updated',    'gh-map-desc');
})();
