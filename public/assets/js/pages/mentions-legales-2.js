// Révélation progressive de l'e-mail — résistant au scraping
function revealEmail(btn) {
  const u = ['contact', 'christopheboutry', 'com'];
  const addr = u[0] + '\u0040' + u[1] + '\u002E' + u[2];
  const a = document.createElement('a');
  a.href = 'mailto:' + addr;
  a.textContent = addr;
  a.style.cssText = 'color:var(--primary);font-size:.9rem;';
  btn.replaceWith(a);
}
