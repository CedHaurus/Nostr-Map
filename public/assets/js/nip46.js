/**
 * nip46.js — Nostr Map
 * Client NIP-46 (Nostr Connect) pour signature distante sur mobile.
 *
 * Protocole :
 *  1. Génère un keypair éphémère côté client
 *  2. Construit une URI nostrconnect:// → QR code / deep link
 *  3. Attend que l'app signer se connecte via le relai
 *  4. Construit l'event NIP-98 avec la pubkey du signer
 *  5. Demande la signature via sign_event
 *  6. Renvoie l'event signé → auth.php inchangé
 */

import { generateSecretKey, getPublicKey, finalizeEvent }
  from 'https://esm.sh/nostr-tools@2';
import * as nip04
  from 'https://esm.sh/nostr-tools@2/nip04';

// Relai NIP-46 public, bien supporté par Amber et les bunkers courants
const NIP46_RELAY   = 'wss://relay.nsecbunker.com';
const SESSION_TIMEOUT = 120_000; // 2 minutes

// ─── Helpers ─────────────────────────────────────────────────────────────────

function bytesToHex(bytes) {
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

function randId() {
  return Math.random().toString(36).slice(2, 10);
}

async function sha256hex(message) {
  const bytes  = new TextEncoder().encode(message);
  const digest = await crypto.subtle.digest('SHA-256', bytes);
  return Array.from(new Uint8Array(digest))
    .map(b => b.toString(16).padStart(2, '0')).join('');
}

// Calcul de l'ID d'un event Nostr (NIP-01)
async function computeEventId(event) {
  const serialized = JSON.stringify([
    0,
    event.pubkey,
    event.created_at,
    event.kind,
    event.tags,
    event.content,
  ]);
  return sha256hex(serialized);
}

// ─── Session NIP-46 ───────────────────────────────────────────────────────────

/**
 * Démarre une session NIP-46.
 *
 * @param {object} opts
 * @param {(uri: string) => void} opts.onURI      - Appelé immédiatement avec nostrconnect://
 * @param {(msg: string) => void} [opts.onStatus] - Messages de statut pour l'UI
 * @returns {Promise<object>} L'event NIP-98 signé, prêt à envoyer à /api/auth.php
 */
export function startNIP46Login({ onURI, onStatus }) {
  return new Promise((resolve, reject) => {

    // 1. Keypair éphémère client
    const clientPriv    = generateSecretKey();       // Uint8Array(32)
    const clientPrivHex = bytesToHex(clientPriv);    // hex pour nip04
    const clientPub     = getPublicKey(clientPriv);  // hex pubkey

    // 2. URI nostrconnect://
    const meta = JSON.stringify({ name: 'Nostr Map', url: location.origin });
    const uri  = `nostrconnect://${clientPub}`
               + `?relay=${encodeURIComponent(NIP46_RELAY)}`
               + `&metadata=${encodeURIComponent(meta)}`;
    onURI(uri);
    onStatus?.('En attente de votre app Nostr…');

    // 3. État de la session
    let ws;
    let signerPub = null;
    const pending = new Map(); // reqId → { resolve, reject }
    let timer;

    function cleanup() {
      clearTimeout(timer);
      try { ws?.close(); } catch {}
    }

    // 4. Timeout global (l'utilisateur a 2 min pour ouvrir l'app)
    timer = setTimeout(() => {
      cleanup();
      reject(new Error('Délai dépassé. L\'app n\'a pas répondu dans les 2 minutes.'));
    }, SESSION_TIMEOUT);

    // ── Envoyer une requête NIP-46 chiffrée et attendre la réponse ───────────
    async function encryptSend(method, params, targetPub) {
      return new Promise(async (res, rej) => {
        const id      = randId();
        const payload = JSON.stringify({ id, method, params });
        try {
          const enc    = await nip04.encrypt(clientPrivHex, targetPub, payload);
          const event  = finalizeEvent({
            kind:       24133,
            created_at: Math.floor(Date.now() / 1000),
            tags:       [['p', targetPub]],
            content:    enc,
          }, clientPriv);

          pending.set(id, { resolve: res, reject: rej });
          ws.send(JSON.stringify(['EVENT', event]));

          // Timeout par requête individuelle (60s)
          setTimeout(() => {
            if (pending.has(id)) {
              pending.delete(id);
              rej(new Error('L\'app n\'a pas répondu à la demande de signature.'));
            }
          }, 60_000);
        } catch (e) { rej(e); }
      });
    }

    // 5. Connexion WebSocket
    try {
      ws = new WebSocket(NIP46_RELAY);
    } catch {
      cleanup();
      reject(new Error('Impossible de se connecter au relai NIP-46.'));
      return;
    }

    const subId = 'nc_' + randId();

    ws.onopen = () => {
      // S'abonner aux messages adressés à notre pubkey éphémère
      ws.send(JSON.stringify(['REQ', subId, {
        kinds: [24133],
        '#p':  [clientPub],
        since: Math.floor(Date.now() / 1000) - 5,
      }]));
    };

    ws.onmessage = async (e) => {
      try {
        const msg = JSON.parse(e.data);
        if (!Array.isArray(msg) || msg[0] !== 'EVENT' || msg[1] !== subId) return;

        const ev = msg[2];
        if (ev?.kind !== 24133) return;

        // Déchiffrer le payload
        let payload;
        try {
          const dec = await nip04.decrypt(clientPrivHex, ev.pubkey, ev.content);
          payload   = JSON.parse(dec);
        } catch { return; } // ignorer les messages illisibles

        // Réponse à une requête en attente (sign_event, etc.)
        if (payload.id && pending.has(payload.id)) {
          const { resolve: res, reject: rej } = pending.get(payload.id);
          pending.delete(payload.id);
          if (payload.error) rej(new Error(payload.error));
          else res(payload.result);
          return;
        }

        // ── Message "connect" initial du signer ──────────────────────────────
        if (!signerPub) {
          signerPub = ev.pubkey;
          clearTimeout(timer);
          onStatus?.('App connectée — demande de signature en cours…');

          // ACK le connect si l'app l'attend
          if (payload.method === 'connect' && payload.id) {
            try {
              const ack    = JSON.stringify({ id: payload.id, result: 'ack', error: null });
              const enc    = await nip04.encrypt(clientPrivHex, signerPub, ack);
              const ackEv  = finalizeEvent({
                kind:       24133,
                created_at: Math.floor(Date.now() / 1000),
                tags:       [['p', signerPub]],
                content:    enc,
              }, clientPriv);
              ws.send(JSON.stringify(['EVENT', ackEv]));
            } catch {}
          }

          // Construire l'event NIP-98 avec la pubkey du signer (l'utilisateur)
          const authUrl = `${location.origin}/api/auth.php`;
          const nip98   = {
            kind:       27235,
            created_at: Math.floor(Date.now() / 1000),
            tags:       [['u', authUrl], ['method', 'POST']],
            content:    '',
            pubkey:     signerPub,
          };
          nip98.id = await computeEventId(nip98);

          // Demander la signature au signer distant
          try {
            const result  = await encryptSend('sign_event', [JSON.stringify(nip98)], signerPub);
            const signed  = typeof result === 'string' ? JSON.parse(result) : result;
            cleanup();
            resolve(signed);
          } catch (err) {
            cleanup();
            reject(err);
          }
        }
      } catch { /* ignorer les erreurs de parsing */ }
    };

    ws.onerror  = () => { cleanup(); reject(new Error('Erreur de connexion au relai NIP-46.')); };
    ws.onclose  = () => { clearTimeout(timer); };
  });
}
