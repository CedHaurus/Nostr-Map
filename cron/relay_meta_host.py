#!/usr/bin/env python3
"""
Relay metadata fetcher — runs on HOST.
Reads /var/www/nostrmap/storage/relay_queue.json  (written by PHP cron)
Writes /var/www/nostrmap/storage/relay_cache.json (read by PHP cron)

Cron : */30 * * * * root python3 /var/www/nostrmap/cron/relay_meta_host.py >> /var/log/nostrmap_relay_meta.log 2>&1
"""
import asyncio, json, os, sys, time
from datetime import datetime
import websockets

QUEUE_FILE  = "/var/www/nostrmap/public/storage/relay_queue.json"
CACHE_FILE  = "/var/www/nostrmap/public/storage/relay_cache.json"
CACHE_TMP   = CACHE_FILE + ".tmp"

RELAYS = [
    "wss://relay.nostrmap.net",
    "wss://relay.damus.io",
    "wss://nos.lol",
]

MAX_PER_RUN    = 30
DELAY_BETWEEN  = 0.3

def log(msg):
    print(f"[relay_meta] {datetime.now():%Y-%m-%d %H:%M:%S} {msg}", flush=True)

def load_json(path: str, default):
    try:
        with open(path) as f:
            return json.load(f)
    except Exception:
        return default

def save_json(path: str, data, tmp: str | None = None):
    target = tmp or path
    with open(target, "w") as f:
        json.dump(data, f, ensure_ascii=False)
    if tmp:
        os.replace(tmp, path)

async def fetch_from_relay(url: str, hex_pubkey: str) -> dict | None:
    req = json.dumps(["REQ", "m1", {"kinds": [0], "authors": [hex_pubkey], "limit": 1}])
    try:
        async with websockets.connect(url, open_timeout=4, close_timeout=1) as ws:
            await ws.send(req)
            best = None
            deadline = asyncio.get_event_loop().time() + 6.0
            while True:
                remaining = deadline - asyncio.get_event_loop().time()
                if remaining <= 0:
                    break
                try:
                    msg = await asyncio.wait_for(ws.recv(), timeout=min(remaining, 3.0))
                except asyncio.TimeoutError:
                    break
                try:
                    data = json.loads(msg)
                except Exception:
                    continue
                if not isinstance(data, list):
                    continue
                if data[0] == "EOSE":
                    break
                if data[0] == "EVENT" and len(data) >= 3:
                    ev = data[2]
                    if isinstance(ev, dict) and ev.get("kind") == 0:
                        if not best or ev.get("created_at", 0) > best.get("created_at", 0):
                            best = ev
            if best:
                c = json.loads(best.get("content", "{}"))
                return {
                    "name":       (c.get("display_name") or c.get("name") or "")[:100],
                    "nostr_name": (c.get("name") or "")[:100],
                    "picture":    (c.get("picture") or "")[:500],
                    "about":      (c.get("about") or "")[:2000],
                    "nip05":      (c.get("nip05") or "")[:200],
                }
    except Exception:
        pass
    return None

async def fetch_meta(hex_pubkey: str) -> dict | None:
    tasks = [fetch_from_relay(url, hex_pubkey) for url in RELAYS]
    results = await asyncio.gather(*tasks, return_exceptions=True)
    for r in results:
        if isinstance(r, dict) and any(v for v in r.values() if v):
            return r
    return None

def main():
    log("Start")

    queue: list = load_json(QUEUE_FILE, [])
    if not queue:
        log("Queue vide — rien à faire.")
        return

    cache: dict = load_json(CACHE_FILE, {})
    to_process = [h for h in queue if h not in cache][:MAX_PER_RUN]

    if not to_process:
        log("Tous les items de la queue sont déjà en cache — nettoyage queue.")
        save_json(QUEUE_FILE, [])
        return

    log(f"{len(to_process)} hex à fetcher")
    updated = 0

    for hex_pk in to_process:
        meta = asyncio.run(fetch_meta(hex_pk))
        if meta and any(v for v in meta.values() if v):
            cache[hex_pk] = meta
            log(f"  OK: {meta.get('name', hex_pk[:16]+'…')}")
            updated += 1
        else:
            log(f"  MISS: {hex_pk[:16]}…")
        time.sleep(DELAY_BETWEEN)

    # Sauvegarder le cache (atomic write)
    save_json(CACHE_FILE, cache, CACHE_TMP)

    # Retirer de la queue les items traités avec succès
    new_queue = [h for h in queue if h not in cache]
    save_json(QUEUE_FILE, new_queue)

    log(f"Done — {updated}/{len(to_process)} mis en cache")

if __name__ == "__main__":
    main()
