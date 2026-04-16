# Nostr Map ✦

**Annuaire Nostr Francophone** — [nostrmap.fr](https://nostrmap.fr)

Répertoire des profils Nostr francophones avec vérification de liens réseaux sociaux, QR codes et authentification NIP-07.

---

## Stack

| Composant | Technologie |
|-----------|-------------|
| Reverse proxy | Nginx (Alpine) |
| Backend | PHP 8.3-FPM |
| Base de données | MySQL 8 |
| Frontend | HTML + Vanilla JS (ES Modules) + CSS custom |
| Auth | NIP-07 (extension navigateur) + NIP-98 (HTTP Auth) |
| Infra | Docker + Docker Compose |

---

## Prérequis

- Docker ≥ 24
- Docker Compose ≥ 2.x
- Un reverse proxy externe (Nginx / Caddy) gérant le SSL sur le VPS
- Extension Nostr dans le navigateur : [Alby](https://getalby.com), [nos2x](https://github.com/fiatjaf/nos2x), ou [Flamingo](https://www.getflamingo.org/)

---

## Installation

### 1. Cloner / déposer les fichiers

```bash
git clone <repo> /var/www/nostrmap
cd /var/www/nostrmap
```

### 2. Configurer l'environnement

```bash
cp .env.example .env
nano .env
```

Modifier obligatoirement :

```env
MYSQL_ROOT_PASSWORD=<mot_de_passe_root_fort>
MYSQL_PASSWORD=<mot_de_passe_db_fort>
JWT_SECRET=<chaîne_aléatoire_min_32_chars>
APP_URL=https://nostrmap.fr
```

Générer un JWT secret sécurisé :
```bash
openssl rand -hex 32
```

### 3. Lancer les conteneurs

```bash
docker compose up -d --build
```

Le schéma SQL est appliqué automatiquement au premier démarrage de MySQL.

Vérifier que tout tourne :
```bash
docker compose ps
docker compose logs -f
```

### 4. Configurer le reverse proxy du VPS

Nginx hôte (exemple) — ajouter dans votre config SSL existante :

```nginx
server {
    listen 443 ssl http2;
    server_name nostrmap.fr www.nostrmap.fr;

    ssl_certificate     /etc/letsencrypt/live/nostrmap.fr/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/nostrmap.fr/privkey.pem;

    location / {
        proxy_pass         http://127.0.0.1:8080;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
        proxy_read_timeout 30s;
    }
}

server {
    listen 80;
    server_name nostrmap.fr www.nostrmap.fr;
    return 301 https://$host$request_uri;
}
```

Avec **Caddy** (encore plus simple) :
```
nostrmap.fr {
    reverse_proxy 127.0.0.1:8080
}
```

---

## Crons

Ajouter au crontab de l'hôte (`crontab -e`) :

```cron
# Mise à jour cache profils Nostr (métadonnées + stats) — toutes les heures
0 * * * * docker exec nostrmap_php php /var/www/cron/update_nostr_cache.php >> /var/log/nostrmap_cache.log 2>&1

# Revérification des liens sociaux vérifiés — chaque nuit à 4h
0 4 * * * docker exec nostrmap_php php /var/www/cron/recheck_links.php >> /var/log/nostrmap_recheck.log 2>&1

```

---

## Commandes utiles

```bash
# Voir les logs en temps réel
docker compose logs -f nginx
docker compose logs -f php

# Accéder à MySQL
docker exec -it nostrmap_mysql mysql -u nostrmap -p nostrmap

# Rebuild après modification du Dockerfile PHP
docker compose up -d --build php

# Arrêter sans supprimer les volumes
docker compose stop

# Tout supprimer (données comprises !)
docker compose down -v
```

---

## Architecture API

| Endpoint | Méthode | Auth | Description |
|----------|---------|------|-------------|
| `/api/auth.php` | POST | — | Login NIP-98, retourne JWT |
| `/api/profile.php?slug=X` | GET | — | Profil public + liens |
| `/api/profile.php` | POST | ✓ | Mise à jour cache profil |
| `/api/profile.php?action=add_link` | POST | ✓ | Ajouter un lien RS |
| `/api/profile.php?action=delete_link&id=N` | DELETE | ✓ | Supprimer un lien |
| `/api/search.php?q=X` | GET | — | Recherche full-text |
| `/api/search.php?sort=recent` | GET | — | Derniers inscrits |
| `/api/verify.php` | POST | ✓ | Vérifier un lien RS |

---

## Authentification

Le flux complet NIP-07 + NIP-98 :

1. L'utilisateur clique **Se connecter**
2. `window.nostr.getPublicKey()` → récupère la clé publique hex
3. Construction d'un event `kind:27235` avec les tags `["u", url]` et `["method", "POST"]`
4. `window.nostr.signEvent(event)` → l'extension signe l'event
5. POST vers `/api/auth.php` avec l'event signé
6. PHP vérifie : ID correct, signature Schnorr BIP-340, `created_at` < 60s
7. JWT 24h retourné → stocké dans `sessionStorage`

---

## Vérification des liens RS

1. Ajouter un lien → le serveur génère un challenge `nostrmap:[npub8]:[6chars]`
2. L'utilisateur colle ce code dans sa bio / description sur le réseau cible
3. Clic **Vérifier** → le serveur fetch l'URL et cherche le challenge dans le HTML
4. Si trouvé → `verified = true` avec timestamp

Le cron `recheck_links.php` revérifie quotidiennement les liens déjà validés.

---

## Sécurité

- Signatures Schnorr BIP-340 vérifiées en PHP pur (GMP)
- JWT HS256 avec secret fort
- Protection SSRF sur les fetch curl (blocage IPs privées)
- Headers sécurité Nginx : X-Frame-Options, X-Content-Type-Options
- Préparations PDO paramétrées (pas d'injection SQL)
- Limite 10 liens par profil, 512 Ko max par fetch de vérification

---

## Structure des fichiers

```
/var/www/nostrmap/
├── docker-compose.yml
├── .env                        ← à créer depuis .env.example
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
├── config/db.php
├── sql/schema.sql
├── public/
│   ├── index.html
│   ├── mon-profil.html
│   ├── p.html
│   ├── assets/
│   │   ├── css/app.css
│   │   └── js/
│   │       ├── app.js
│   │       ├── auth.js
│   │       ├── nostr.js
│   │       ├── verify.js
│   │       └── qr.js
│   └── api/
│       ├── _helpers.php        ← Schnorr, JWT, bech32
│       ├── auth.php
│       ├── profile.php
│       ├── search.php
│       └── verify.php
└── cron/
    ├── update_nostr_cache.php
    └── recheck_links.php
```
