import { nostr } from "/assets/js/nostr.js";

// Valeurs transmises par la page PHP (attributs data-… de #profile-edit-data)
const _data  = document.getElementById("profile-edit-data").dataset;
const _npub  = _data.npub;
const _csrf  = _data.csrf;
const _autoR = _data.autoRefresh === "1";

function npubToHex(npub) {
  const C = "qpzry9x8gf2tvdw0s3jn54khce6mua7l";
  const s = npub.toLowerCase(), sep = s.lastIndexOf("1");
  if (sep < 1) throw new Error("bad npub");
  let acc = 0, bits = 0; const bytes = [];
  for (const c of s.slice(sep + 1, -6)) {
    const i = C.indexOf(c); if (i < 0) throw new Error("bad char");
    acc = (acc << 5) | i; bits += 5;
    while (bits >= 8) { bits -= 8; bytes.push((acc >> bits) & 0xff); }
  }
  return bytes.map(b => b.toString(16).padStart(2,"0")).join("");
}

function setLabel(msg) {
  const el = document.getElementById("refresh-label");
  if (el) el.textContent = msg;
}

window.startNostrRefresh = async function() {
  const overlay = document.getElementById("nostr-refresh-overlay");
  overlay.style.display = "flex";
  const btn = document.getElementById("btn-nostr-refresh");
  if (btn) btn.disabled = true;

  const data = { npub: _npub, csrf_token: _csrf };
  let pubHex;
  try { pubHex = npubToHex(_npub); } catch {}

  setLabel("Connexion aux relais Nostr…");

  await new Promise(resolve => {
    let profileDone = false;

    // Timeout global 10s
    const gTimeout = setTimeout(() => { profileDone = true; resolve(); }, 10000);

    nostr.fetchProfile(_npub, (live) => {
      if (profileDone) return;

      if (!live) {
        // Relay timeout avec null = plus rien à attendre
        clearTimeout(gTimeout);
        profileDone = true;
        resolve();
        return;
      }

      setLabel("Profil trouvé — récupération des stats…");
      if (live.name || live.display_name) data.cached_name   = live.name || live.display_name;
      if (live.picture)                   data.cached_avatar = live.picture;
      if (live.about)                     data.cached_bio    = live.about;
      if (live.nip05)                     data.cached_nip05  = live.nip05;

      if (pubHex && !profileDone) {
        profileDone = true;
        clearTimeout(gTimeout); // éviter race condition : gTimeout ne doit pas résoudre avant fetchStats
        nostr.fetchStats(pubHex, null, (stats) => {
          if (stats.followers != null)  data.nostr_followers  = stats.followers;
          if (stats.posts != null)      data.nostr_posts      = stats.posts;
          if (stats.createdAt)          data.nostr_created_at = stats.createdAt;
          resolve();
        });
      }
    });
  });

  setLabel("Enregistrement…");

  try {
    await fetch("/admin/nostr-refresh.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
  } catch (e) { console.error("[nostr-refresh]", e); }

  // Recharger sans le param nostr_refresh
  const url = new URL(window.location.href);
  url.searchParams.delete("nostr_refresh");
  url.searchParams.set("refreshed", "1");
  window.location.href = url.toString();
};

// Auto-refresh si redirigé depuis profile-create
if (_autoR) {
  window.startNostrRefresh();
}
