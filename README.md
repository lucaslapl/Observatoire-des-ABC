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

Le collect des sources en ligne (échantillonnées en cache) aboutit à un jeu
de référence de **1263 projets, 5421 lignes communes, 1958 snapshots** (GeoJSON
= 5420 points, attributs paritaires à l'ancien endpoint). Les données de
travail humain (`verifications`, `contributions`, `audit_log`) sont préservées
d'un collect à l'autre et jamais écrasées.

## Commandes artisan

| Commande | Rôle |
| --- | --- |
| `abc:import-legacy` | Importe `data/abc.db` (SQLite) vers PostgreSQL |
| `abc:collect` | Collecte des 4 sources puis statuts, géocodage, anomalies |
| `abc:collect --init` | Même collect mais **sans purge** (upsert = synchronisation, pas de superuser requis) |
| `abc:export-deploy` | Export portable (SQL, sans `geom`) pour import Plesk sans PostGIS |
| `abc:status` | Recalcule les statuts et les anomalies |
| `abc:geocode` | Géocode les communes manquantes (geo.api.gouv.fr) |
| `abc:export --fmt=csv\|geojson` | Export CSV ou GeoJSON (`storage/app/abc/exports`) |
| `abc:verify` | Worklist de vérification (CSV) |
| `abc:backup` | Sauvegarde pg_dump avec rotation (14) |

> **`abc:collect` et la purge** : le collect vide puis ré-ingère les tables
> *re-collectables* (`projets`, `communes`, `snapshots`) sans toucher au travail
> humain (`verifications`, `contributions`, `audit_log`). Pour cela il
> désactive temporairement les contraintes de clé étrangère — via
> `SET session_replication_role = replica` sur PostgreSQL, ce qui **exige un
> rôle superuser** pour la collecte en production.
>
> **Source Fonds vert** : les exports `fonds-vert-p113-*.csv` actuellement pinnés
> ne listent que **65 projets ABC** (16 en 2024, 49 en 2025). L'ancienne base
> `data/abc.db` en contenait 140 — issue d'une version plus riche de ces
> exports qui n'est plus disponible. Le collect reflète donc désormais les
> sources en ligne (1263 projets au total, soit 75 « à venir » de moins que
> l'ancien jeu de données).
>
> **Exclusions** : un projet supprimé depuis la carte (admin) est enregistré
> dans `project_exclusions` ; le collect **ne le ré-importe pas** tant que
> l'exclusion n'est pas levée depuis le panneau admin.
>
> **Hébergements sans superuser** : le collect classique purge via
> `session_replication_role = replica` (réservé superuser). Sur de tels
> hébergements, utiliser `abc:collect --init` (synchro sans purge) et
> `COLLECT_AUTOMATIC=false` (le scheduler lancera alors `abc:collect --init`).

## Planification (CRON)

Sur le serveur, un seul CRON suffit (Laravel scheduler) :

```
* * * * * cd /chemin/vers/abc_scrapper && /chemin/php/php artisan schedule:run >> /dev/null 2>&1
```

Tâches planifiées (`routes/console.php`) :

- `abc:backup` tous les jours à 04:00
- `abc:collect --init` le 1er de chaque mois à 03:00 (si `COLLECT_AUTOMATIC=true`)

## Déploiement (PulseHeberg / Plesk)

> **Sans SSH** : les commandes (`composer`, `npm`, `php artisan`) s'exécutent
> via des **Scheduled Tasks Plesk** jetables.
>
> **Sans PostGIS** (cas mutualisé) : voir « Chemin B » en fin de section.

1. **Base de données** : créer une base PostgreSQL dans l'outil Bases de données
   de Plesk. PostGIS n'est **pas requis** : le schéma ignore `geom` s'il est
   absent (`abc:export-deploy` produit un SQL sans la colonne géométrique).
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
   COLLECT_AUTOMATIC=false
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
   - Hébergement **avec PostGIS** : `php artisan abc:import-legacy`, puis un collect.
   - Hébergement **sans PostGIS** : voir « Chemin B » ci-dessous.

### Chemin B — sans PostGIS (mutualisé)

1. Sur le poste local (base PostGIS actuelle), générer l'export portable :
   ```bash
   php artisan abc:export-deploy
   # → storage/app/abc/deploy/abc-deploy.sql
   ```
2. Créer la base PostgreSQL dans Plesk ; `php artisan migrate --force` sur le
   serveur (le schéma saute `geom` si PostGIS est absent).
3. Uploader puis **importer** `abc-deploy.sql` (Plesk → Base de données →
   Importer) : porte toutes les données utiles (projets, communes, snapshots,
   vérifications, contributions, geo_cache, utilisateur/admin, …).
4. Synchroniser le cache du collect (offline) :
   `data/cache/*` → `storage/app/abc/cache/` (copie en ligne de commande).
5. Mises à jour ultérieures : `abc:collect --init` (sync sans purge) ou
   re-générer/importer un nouveau `abc-deploy.sql`.

## Rôles & permissions

Rôles Spatie (`db:seed`) : `admin`, `moderateur`, `contributeur`.

- `admin` : panneau complet (modération, collecte, sauvegarde, actualités,
  exclusions + export du journal d'audit), suppression de projets depuis la carte.
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
| `DELETE /api/admin/projets/{id}` | admin | Supprime un projet et l'exclut du collect |
| `GET /api/admin/exclusions` | admin | Liste des exclusions en cours |
| `DELETE /api/admin/exclusions/{id}` | admin | Lève une exclusion (ré-import au prochain collect) |
| `GET /api/admin/exclusions/export` | admin | Journal d'audit CSV (suppressions / levées) |
| `GET /api/diag` | public | Diagnostic (aucun secret) |

## Corrections & exclusions

Deux flux complémentaires pour maintenir les données :

- **Signaler une correction** (public) : popup de la carte → « 💡 Signaler une
  correction » → contribution modérée par un admin (`/api/contributions`).
- **Vérifier** (admin) : page `/verify` (worklist « À vérifier » par défaut,
  filtres *Vérifiés* / *Tous* pour revoir ou corriger un projet déjà confirmé).
- **Supprimer un projet** (admin uniquement) : bouton 🗑 dans le popup de la
  carte (marqueurs simples et agrégés). Le projet est supprimé avec sa fiche
  et **exclu du prochain collect** (table `project_exclusions`, motif optionnel
  consigné dans `audit_log`).
- **Journal d'audit** : panneau admin → « Exclusions » → *⬇ Exporter le journal
  d'audit* (CSV des suppressions et levées d'exclusion).
- **Levée d'exclusion** : panneau admin → « ↩ Ré-import » ; le projet reviendra
  au prochain collect.

Sur la carte, quand plusieurs ABC cohabitent au même point, un **marqueur
agrégé** (réduit en taille pour ne pas être confondu avec les points
intercommunaux) liste chaque projet avec son **statut, son année, sa source**
et son verdict ; un clic déplie les communes reliées par des traits.

## Tests & style

```bash
php artisan test                 # 45 tests (slug, statuts, anomalies, CSV, contributions, GeoJSON, exclusions)
./vendor/bin/pint                # formatage
npm run build                    # compilation frontend (Vite)
```

CI
- `.github/workflows/tests.yml` : PHP 8.3 + Composer + build Vite + PHPUnit
  (avec `php artisan key:generate`) + Pint.
- `.github/workflows/ci.yml` : Composer + build Vite sous Node 24 (Ziggy est
  fourni par Composer via `vendor/tightenco/ziggy`).
- Actions GitHub en v5 (`actions/checkout@v5`, `actions/cache@v5`) pour lever
  la dépréciation Node 20.

## Licence

GPL-3.0 — voir `LICENSE` (projet d'origine « Observatoire des ABC »).
