/**
 * nostr.js — Nostr Map
 * Fetch de profils kind:0 depuis les relais publics (WebSocket).
 * Cache localStorage TTL 1h.
 */

const RELAYS = [
  'wss://relay.damus.io',
  'wss://nos.lol',
  'wss://relay.nostr.band',
  'wss://nostr.fr',
  'wss://relay.primal.net',
  'wss://purplepag.es',
];

const CACHE_TTL = 60 * 60 * 1000; // 1 heure en ms
const CACHE_PREFIX = 'nostrmap_profile_';

// ─── Cache ────────────────────────────────────────────────────────────────────

function getCached(npub) {
  try {
    const raw = localStorage.getItem(CACHE_PREFIX + npub);
    if (!raw) return null;
    const { data, ts } = JSON.parse(raw);
    if (Date.now() - ts > CACHE_TTL) {
      localStorage.removeItem(CACHE_PREFIX + npub);
      return null;
    }
    return data;
  } catch {
    return null;
  }
}

function setCache(npub, data) {
  try {
    localStorage.setItem(CACHE_PREFIX + npub, JSON.stringify({ data, ts: Date.now() }));
  } catch {
    // localStorage plein, on ignore
  }
}

// ─── Connexion WebSocket à un relai ──────────────────────────────────────────

function connectRelay(url, pubkeyHex, onEvent, onEose) {
  return new Promise((resolve) => {
    let ws;
    const timeout = setTimeout(() => {
      ws?.close();
      resolve(null);
    }, 5000);

    try {
      ws = new WebSocket(url);
    } catch {
      clearTimeout(timeout);
      resolve(null);
      return;
    }

    const subId = 'nm_' + Math.random().toString(36).slice(2, 9);

    ws.onopen = () => {
      ws.send(JSON.stringify([
        'REQ',
        subId,
        { kinds: [0], authors: [pubkeyHex], limit: 1 },
      ]));
    };

    ws.onmessage = (e) => {
      try {
        const msg = JSON.parse(e.data);
        if (!Array.isArray(msg)) return;

        if (msg[0] === 'EVENT' && msg[1] === subId && msg[2]) {
          const event = msg[2];
          if (event.kind === 0) {
            try {
              const content = JSON.parse(event.content);
              content._created_at = event.created_at;
              onEvent(content);
            } catch { /* JSON malformé */ }
          }
        }

        if (msg[0] === 'EOSE' && msg[1] === subId) {
          ws.send(JSON.stringify(['CLOSE', subId]));
          ws.close();
          clearTimeout(timeout);
          onEose?.();
          resolve(ws);
        }
      } catch { /* ignore */ }
    };

    ws.onerror = () => {
      clearTimeout(timeout);
      ws.close();
      resolve(null);
    };

    ws.onclose = () => {
      clearTimeout(timeout);
      resolve(null);
    };
  });
}

// ─── npub → pubkey hex ────────────────────────────────────────────────────────

function npubToHex(npub) {
  const CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

  const str = npub.toLowerCase();
  const sep = str.lastIndexOf('1');
  if (sep < 1) throw new Error('Invalid npub');

  const dataStr = str.slice(sep + 1, -6); // enlever les 6 chars de checksum
  const data5   = Array.from(dataStr).map(c => {
    const idx = CHARSET.indexOf(c);
    if (idx < 0) throw new Error('Invalid char: ' + c);
    return idx;
  });

  // 5-bit → 8-bit
  let acc  = 0;
  let bits = 0;
  const bytes = [];
  for (const v of data5) {
    acc   = (acc << 5) | v;
    bits += 5;
    while (bits >= 8) {
      bits -= 8;
      bytes.push((acc >> bits) & 0xff);
    }
  }

  return bytes.map(b => b.toString(16).padStart(2, '0')).join('');
}

// ─── fetchProfile ────────────────────────────────────────────────────────────
// npub : "npub1..."
// callback : appelé avec le profil dès qu'il est disponible (cache ou relay)

function fetchProfile(npub, callback) {
  // 1. Vérifier le cache
  const cached = getCached(npub);
  if (cached) {
    callback(cached);
    return;
  }

  // 2. Convertir npub → hex
  let pubkeyHex;
  try {
    pubkeyHex = npubToHex(npub);
  } catch (e) {
    console.warn('[nostr] npub invalide:', npub, e);
    callback(null);
    return;
  }

  // 3. Interroger les relais en parallèle
  let bestProfile  = null;
  let bestCreatedAt = 0;
  let responded    = 0;

  const handleEvent = (profile) => {
    if ((profile._created_at || 0) > bestCreatedAt) {
      bestCreatedAt = profile._created_at || 0;
      bestProfile   = profile;
      // Appel immédiat pour mise à jour UI progressive
      callback(profile);
    }
  };

  const handleEose = () => {
    responded++;
    if (responded === RELAYS.length && bestProfile) {
      setCache(npub, bestProfile);
    }
  };

  for (const relay of RELAYS) {
    connectRelay(relay, pubkeyHex, handleEvent, handleEose).catch(() => {
      responded++;
    });
  }

  // Timeout global : si aucun relai n'a répondu en 6s
  setTimeout(() => {
    if (!bestProfile) callback(null);
  }, 6000);
}

// ─── fetchStats (nostr.band REST API + WebSocket NIP-45 en parallèle) ────────
// Retourne : { followers, posts, createdAt }
// createdAt est le unix timestamp du kind:0 (passé en paramètre depuis fetchProfile)

async function fetchStats(pubkeyHex, createdAt, callback) {
  // API REST nostr.band (CORS permissif)
  async function tryRestApi() {
    try {
      const ctrl = new AbortController();
      const tid = setTimeout(() => ctrl.abort(), 6000);
      const res = await fetch(
        `https://api.nostr.band/v0/stats/profile/${encodeURIComponent(pubkeyHex)}`,
        { signal: ctrl.signal }
      );
      clearTimeout(tid);
      if (res.ok) {
        const d = await res.json();
        const s = d?.stats?.[pubkeyHex];
        if (s && (s.followers_pubkey_count > 0 || s.note_count > 0)) {
          return { followers: s.followers_pubkey_count ?? 0, posts: s.note_count ?? 0 };
        }
      }
    } catch {}
    return null;
  }

  // Essai 2 : NIP-45 COUNT via plusieurs relais en parallèle → maximum
  const countRelays = [
    'wss://relay.nostr.band',
    'wss://purplepag.es',
    'wss://nostr.fr',
    'wss://relay.primal.net',
    'wss://nos.lol',
  ];

  function tryCountRelay(relayUrl) {
    return new Promise(resolve => {
      const subId = 'nmst_' + Math.random().toString(36).slice(2, 8);
      let followers = null, posts = null, ws;
      const t = setTimeout(() => { try { ws?.close(); } catch {} resolve(null); }, 6000);

      try { ws = new WebSocket(relayUrl); }
      catch { clearTimeout(t); resolve(null); return; }

      ws.onopen = () => {
        ws.send(JSON.stringify(['COUNT', subId + '_f', { kinds: [3], '#p': [pubkeyHex] }]));
        ws.send(JSON.stringify(['COUNT', subId + '_p', { kinds: [1], authors: [pubkeyHex] }]));
      };
      ws.onmessage = (e) => {
        try {
          const msg = JSON.parse(e.data);
          if (!Array.isArray(msg) || msg[0] !== 'COUNT') return;
          const [, sid, d] = msg;
          if (sid === subId + '_f') followers = d?.count ?? 0;
          if (sid === subId + '_p') posts     = d?.count ?? 0;
          if (followers !== null && posts !== null) {
            clearTimeout(t); try { ws.close(); } catch {}
            resolve({ followers, posts });
          }
        } catch {}
      };
      ws.onerror = () => { clearTimeout(t); resolve(null); };
      ws.onclose = () => { clearTimeout(t); resolve(null); };
    });
  }

  // Lancer REST API + tous les relais WS en parallèle, prendre les maximums
  const allPromises = [tryRestApi(), ...countRelays.map(tryCountRelay)];
  Promise.all(allPromises).then(results => {
    let bestF = 0, bestP = 0;
    for (const r of results) {
      if (!r) continue;
      if (r.followers > bestF) bestF = r.followers;
      if (r.posts     > bestP) bestP = r.posts;
    }
    callback({ followers: bestF, posts: bestP, createdAt: createdAt ?? null });
  });
}

// ─── Export ───────────────────────────────────────────────────────────────────

export const nostr = { fetchProfile, fetchStats };
