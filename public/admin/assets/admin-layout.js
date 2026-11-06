/**
 * admin-layout.js — Nostr Map (admin)
 * Barre latérale mobile et widget de maintenance, sortis des scripts intégrés
 * à la page pour la politique de sécurité du contenu (CSP).
 * Chargé avec defer : le DOM est prêt à l'exécution.
 */

// ─── Barre latérale (mobile) ────────────────────────────────────────────────
function toggleSidebar() {
  const s = document.querySelector(".sidebar");
  const b = document.getElementById("sidebar-backdrop");
  if (!s || !b) return;
  const open = s.classList.toggle("open");
  b.classList.toggle("visible", open);
  document.body.style.overflow = open ? "hidden" : "";
}
function closeSidebar() {
  document.querySelector(".sidebar")?.classList.remove("open");
  document.getElementById("sidebar-backdrop")?.classList.remove("visible");
  document.body.style.overflow = "";
}
// Fermer sur navigation (clic sur un lien de la sidebar)
document.querySelectorAll(".nav-link").forEach(a => a.addEventListener("click", closeSidebar));

// ─── Widget de maintenance (admin uniquement) ───────────────────────────────
(function () {
  const widget = document.getElementById("maint-widget");
  if (!widget) return;
  const csrf = widget.dataset.csrf || "";

  function render(active) {
    const dot   = document.getElementById("maint-dot");
    const label = document.getElementById("maint-label");
    const btn   = document.getElementById("maint-btn");
    const bl    = document.getElementById("maint-btn-label");
    if (active) {
      dot.style.background   = "#ef4444";
      dot.style.boxShadow    = "0 0 6px #ef4444";
      label.textContent      = "Maintenance ON";
      label.style.color      = "#ef4444";
      btn.style.background   = "rgba(239,68,68,.15)";
      btn.style.borderColor  = "rgba(239,68,68,.4)";
      btn.style.color        = "#ef4444";
      bl.textContent         = "Désactiver";
    } else {
      dot.style.background   = "#10b981";
      dot.style.boxShadow    = "0 0 6px #10b981";
      label.textContent      = "Site en ligne";
      label.style.color      = "#10b981";
      btn.style.background   = "rgba(16,185,129,.1)";
      btn.style.borderColor  = "rgba(16,185,129,.3)";
      btn.style.color        = "#10b981";
      bl.textContent         = "Maintenance";
    }
  }

  const flag = widget.dataset.active === "1";
  window._maintActive = flag;
  render(flag);

  window.toggleMaintenance = function () {
    const btn = document.getElementById("maint-btn");
    btn.disabled = true;
    fetch("/admin/maintenance-toggle.php", {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: "csrf_token=" + encodeURIComponent(csrf)
    })
      .then(r => r.json())
      .then(d => { window._maintActive = d.active; render(d.active); })
      .catch(() => {
        const label = document.getElementById("maint-label");
        if (label) {
          label.textContent = "Erreur maintenance";
          label.style.color = "#f59e0b";
        }
      })
      .finally(() => { btn.disabled = false; });
  };
})();
