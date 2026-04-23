# Nostr Map

Annuaire francophone de profils Nostr, avec recherche, profils publics, soumission collaborative, vérification de liens sociaux, QR codes et authentification Nostr.

Site public : [nostrmap.fr](https://nostrmap.fr)

## Stack

| Composant | Technologie |
|-----------|-------------|
| Reverse proxy public | Caddy, via le réseau Docker externe `edge` |
| Serveur applicatif | Nginx Alpine |
| Backend | PHP 8.3-FPM |
| Base de données | MySQL 8 |
| Frontend | HTML statique, CSS custom, JavaScript ES Modules |
| Authentification | NIP-07, NIP-98, connexion `nsec`, NIP-46 mobile |
| Déploiement | Docker Compose |

## Prérequis

- Docker 24 ou plus récent
- Docker Compose 2.x
- Un réseau Docker externe nommé `edge`, partagé avec Caddy
- Un domaine pointant vers le serveur
- L'extension navigateur Nostr Map Signer pour la connexion NIP-07

Créer le réseau `edge` si nécessaire :

```bash
docker network create edge
```

## Installation

Cloner le projet et entrer dans le dossier :

```bash
git clone <repo> /var/www/nostrmap
cd /var/www/nostrmap
```

Créer la configuration locale :

```bash
cp .env.example .env
nano .env
```

Valeurs à définir au minimum :

```env
MYSQL_ROOT_PASSWORD=<mot_de_passe_root_fort>
MYSQL_PASSWORD=<mot_de_passe_db_fort>
JWT_SECRET=<chaine_aleatoire_min_32_caracteres>
TOTP_ENCRYPTION_KEY=<chaine_aleatoire_32_caracteres>
APP_URL=https://nostrmap.fr
APP_ENV=production
```

Générer un secret JWT :

```bash
openssl rand -hex 32
```

Lancer les services :

```bash
docker compose up -d --build
```

Vérifier l'état des conteneurs :

```bash
docker compose ps
docker compose logs -f nginx
```

## Reverse Proxy Caddy

Le service `nostrmap_nginx` n'expose pas de port directement sur l'hôte. Caddy doit être connecté au réseau Docker `edge` et joindre Nginx par son nom de service.

Exemple de bloc Caddy :

```caddyfile
nostrmap.fr {
    reverse_proxy nostrmap_nginx:80
}
```

Le fichier Nginx applicatif se trouve dans `docker/nginx/default.conf`. Il gère notamment :

- les URLs canoniques sans extension `.html`
- le mode maintenance public
- le bypass maintenance admin via cookie `nm_preview`
- les routes `/p/<slug>` vers `p.php`
- les API PHP sous `/api/*.php`
- les headers de sécurité et de cache

## Frontend Public

La feuille publique canonique est :

```text
public/assets/css/app.css
```

Les pages publiques principales doivent toutes charger :

```html
<link rel="stylesheet" href="/assets/css/app.css?v=20260423-clean" />
```

Ne pas créer de feuille publique parallèle. Les évolutions du header, du menu mobile et des pages publiques doivent être intégrées dans `app.css` et dans les pages HTML concernées.

Pages publiques principales :

- `index.html`
- `connexion.html`
- `faq.html`
- `mon-profil.html`
- `soumettre.html`
- `p.html`
- `404.html`
- `contact.html`
- `mentions-legales.html`

Pages autonomes :

- `maintenance.html`
- `privacy-nostr-map-extension.html`

## Authentification

Le flux principal utilise NIP-07 et NIP-98 :

1. L'utilisateur clique sur **Se connecter**.
2. Le navigateur expose `window.nostr` via Nostr Map Signer.
3. Le frontend construit un événement NIP-98 `kind:27235`.
4. L'extension signe l'événement.
5. Le frontend envoie l'événement signé à `/api/auth.php`.
6. Le serveur vérifie l'identifiant, la signature Schnorr BIP-340 et la fraîcheur de l'événement.
7. Un JWT est retourné et stocké en `sessionStorage`.

Le site propose aussi une connexion par clé `nsec` et un flux mobile NIP-46.

## API

| Endpoint | Méthode | Auth | Description |
|----------|---------|------|-------------|
| `/api/auth.php` | POST | Non | Connexion Nostr, retourne un JWT |
| `/api/profile.php?slug=<slug>` | GET | Non | Profil public et liens associés |
| `/api/profile.php` | POST | Oui | Mise à jour du profil connecté |
| `/api/profile.php?action=add_link` | POST | Oui | Ajout d'un lien social |
| `/api/profile.php?action=delete_link&id=<id>` | DELETE | Oui | Suppression d'un lien social |
| `/api/profile.php?action=request_deletion` | DELETE | Oui | Demande de suppression du profil connecté |
| `/api/search.php?q=<terme>` | GET | Non | Recherche de profils |
| `/api/search.php?sort=recent` | GET | Non | Derniers profils ajoutés |
| `/api/propose.php` | POST | Oui | Soumission d'un profil à modérer |
| `/api/verify.php` | POST | Oui | Vérification d'un lien social |
| `/api/report.php` | POST | Non | Signalement d'un profil |
| `/api/contact.php` | POST | Non | Message de contact |

## Vérification Des Liens Sociaux

1. L'utilisateur ajoute un lien depuis son dashboard.
2. Le serveur génère un challenge unique.
3. L'utilisateur publie temporairement ce challenge sur le compte externe à vérifier.
4. Le serveur récupère l'URL fournie et cherche le challenge dans le contenu.
5. Si le challenge est trouvé, le lien passe en vérifié.

Le challenge peut ensuite être retiré. Le badge reste acquis : il n'y a pas de revérification périodique. Si l'utilisateur change l'URL d'un lien, ce lien repasse à vérifier.

## Crons

Ajouter au crontab de l'hôte :

```cron
# Mise à jour cache profils Nostr
0 * * * * docker exec nostrmap_php php /var/www/cron/update_nostr_cache.php >> /var/log/nostrmap_cache.log 2>&1
```

## Commandes Utiles

```bash
# Logs applicatifs
docker compose logs -f nginx
docker compose logs -f php

# Accès MySQL
docker exec -it nostrmap_mysql mysql -u nostrmap -p nostrmap

# Rebuild PHP après modification du Dockerfile
docker compose up -d --build php

# Redémarrer Nginx après modification de la configuration
docker compose restart nginx

# Arrêter sans supprimer les volumes
docker compose stop

# Supprimer les conteneurs et volumes
docker compose down -v
```

## Sécurité

- Secrets uniquement dans `.env`, jamais dans Git
- Requêtes SQL préparées via PDO
- Signatures Nostr vérifiées côté serveur
- JWT signé avec secret fort
- Protection SSRF sur les vérifications de liens
- Headers de sécurité appliqués par Nginx et Caddy
- Dossier `public/storage/` inaccessible publiquement
- Pages admin marquées `noindex`

## Structure

```text
/var/www/nostrmap/
├── docker-compose.yml
├── .env.example
├── docker/
│   ├── nginx/default.conf
│   └── php/Dockerfile
├── config/
│   └── db.php
├── cron/
│   ├── recheck_links.php
│   └── update_nostr_cache.php
├── public/
│   ├── index.html
│   ├── connexion.html
│   ├── faq.html
│   ├── mon-profil.html
│   ├── soumettre.html
│   ├── p.html
│   ├── p.php
│   ├── maintenance.html
│   ├── privacy-nostr-map-extension.html
│   ├── admin/
│   ├── api/
│   └── assets/
│       ├── css/app.css
│       ├── img/
│       └── js/
└── sql/
    └── schema.sql
```
