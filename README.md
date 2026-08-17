# Observatoire des ABC

Observatoire national des Atlas de la Biodiversité Communale (ABC). Suivi des
projets financés (data.gouv / Fonds vert), archives 2022 (Wayback), vérifications
manuelles, contributions du public et export cartographique.

Application **Laravel 12 + Vue 3 (Inertia) + PostgreSQL/PostGIS**, migration de
l'ancien serveur Node/TS/SQLite (fichiers `server/` et `src/` conservés comme
filet de secours et source de vérité).

## Prérequis

- PHP **8.3** (extensions `pdo_pgsql`, `pgsql`, `intl`, `mbstring`)
- Composer 2
- Node 20+ / npm
- PostgreSQL 16 **+ PostGIS** (dev : Docker ; prod : extension de l'hébergeur)

## Installation (développement local)

```bash
# 1. Base PostGIS via Docker (conteneur abc-postgis, port hôte 5433)
docker compose up -d

# 2. Dépendances
composer install
npm install

# 3. Environnement
cp .env.example .env
php artisan key:generate
# ajuster .env si besoin (DB_*, ADMIN_*)

# 4. Schéma + compte admin (seedé depuis ADMIN_*)
php artisan migrate
php artisan db:seed

# 5. Import des données historiques depuis l'ancienne base SQLite (une seule fois)
php artisan abc:import-legacy

# 6. Frontend
npm run build        # production
npm run dev          # développement (Vite + HMR)

# 7. Serveur local
php artisan serve
```

Parité vérifiée après import : 1338 projets, 5437 communes, 1958 snapshots,
5 vérifications, 1 contribution, 2 entrées d'audit ; GeoJSON identique à
l'ancien endpoint.

## Commandes artisan

| Commande | Rôle |
| --- | --- |
| `abc:import-legacy` | Importe `data/abc.db` (SQLite) vers PostgreSQL |
| `abc:collect` | Collecte des 4 sources puis statuts, géocodage, anomalies |
| `abc:status` | Recalcule les statuts et les anomalies |
| `abc:geocode` | Géocode les communes manquantes (geo.api.gouv.fr) |
| `abc:export --fmt=csv\|geojson` | Export CSV ou GeoJSON (`storage/app/abc/exports`) |
| `abc:verify` | Worklist de vérification (CSV) |
| `abc:backup` | Sauvegarde pg_dump avec rotation (14) |

## Planification (CRON)

Sur le serveur, un seul CRON suffit (Laravel scheduler) :

```
* * * * * cd /chemin/vers/abc_scrapper && /chemin/php/php artisan schedule:run >> /dev/null 2>&1
```

Tâches planifiées (`routes/console.php`) :

- `abc:backup` tous les jours à 04:00
- `abc:collect` le 1er de chaque mois à 03:00

## Déploiement (PulseHeberg / Plesk)

1. **Base de données** : créer une base PostgreSQL avec l'extension PostGIS
   (`CREATE EXTENSION IF NOT EXISTS postgis;`) dans l'outil Bases de données
   de Plesk.
2. **Fichiers** : cloner/push le dépôt dans `httpdocs` (ou sous-répertoire), à
   l'exclusion de `.env`, `storage/*/private`, `data/abc.db`.
3. **PHP** : sélectionner PHP 8.3 dans Plesk (PHP-FPM recommandé), activer
   `pdo_pgsql`, `pgsql`, `intl`, `mbstring`.
4. **`.env`** de production :
   ```dotenv
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://votre-domaine.fr
   DB_CONNECTION=pgsql
   DB_HOST=localhost
   DB_PORT=5432
   DB_DATABASE=...
   DB_USERNAME=...
   DB_PASSWORD=...
   SESSION_DRIVER=database
   SESSION_SECURE_COOKIE=true
   ADMIN_USERNAME=admin
   ADMIN_EMAIL=admin@exemple.fr
   ADMIN_PASSWORD=mot-de-passe-fort
   ```
5. **Dépendances & assets** :
   ```bash
   composer install --no-dev --optimize-autoloader
   npm ci && npm run build
   php artisan key:generate
   php artisan migrate --force
   php artisan db:seed --force
   php artisan config:cache && php artisan route:cache && php artisan view:cache
   ```
6. **Permissions** : `storage/` et `bootstrap/cache/` accessibles en écriture
   par l'utilisateur web.
7. **CRON** (Plesk → Scheduled Tasks) :
   ```
   /opt/plesk/php/8.3/bin/php /var/www/vhosts/.../httpdocs/artisan schedule:run
   ```
   fréquence : toutes les minutes.
8. **Premier import des données** (une seule fois, après migration) :
   `php artisan abc:import-legacy`.

## Rôles & permissions

Rôles Spatie (`db:seed`) : `admin`, `moderateur`, `contributeur`.

- `admin` : panneau complet (modération, collecte, sauvegarde, actualités).
- `moderateur` : modération des contributions et actualités.
- `contributeur` : compte connecté (proposition de données).

Le compte administrateur est créé depuis `.env` (`ADMIN_USERNAME`,
`ADMIN_EMAIL`, `ADMIN_PASSWORD`) par `db:seed`. Connexion au panneau via
`/login` (email + mot de passe, Breeze standard).

## Endpoints API

| Endpoint | Accès | Description |
| --- | --- | --- |
| `GET /api/abc.geojson` | public | GeoJSON de la carte (FeatureCollection) |
| `GET /api/meta` | public | Statistiques globales + dates des sources |
| `GET /api/stats` | public | Comptage par statut |
| `GET /api/verifications` | public | Worklist de vérification |
| `POST /api/verifications` | admin | Enregistre une vérification |
| `GET /api/contributions` | public | Liste des contributions |
| `POST /api/contributions` | public (10/h/IP) | Propose une contribution |
| `GET /api/admin/contributions` | admin | Liste des contributions |
| `POST /api/admin/contributions/{id}/valider` | admin | Valide une contribution |
| `POST /api/admin/contributions/{id}/refuser` | admin | Refuse une contribution |
| `POST /api/admin/contributions/{id}/retirer` | admin | Retire une contribution validée |
| `POST /api/admin/backup` | admin | Sauvegarde immédiate |
| `POST /api/admin/collect` | admin | Relance la collecte |
| `GET /api/diag` | public | Diagnostic (aucun secret) |

## Tests & style

```bash
php artisan test                 # 40 tests (slug, statuts, anomalies, CSV, contributions, GeoJSON)
./vendor/bin/pint                # formatage
npm run build                    # compilation frontend
```

CI (`.github/workflows/tests.yml`) : PHP 8.3 + Composer + build Vite + PHPUnit
+ Pint.

## Licence

GPL-3.0 — voir `LICENSE` (projet d'origine « Observatoire des ABC »).
