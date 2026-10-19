# AGENTS.md — Observatoire des ABC

## Qu'est-ce que ce projet

Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des
projets ABC financés (data.gouv / Fonds vert), archives 2022 (Wayback Machine),
vérifications manuelles, contributions du public, carte Leaflet et export
CSV/GeoJSON. Site public : <https://observatoire-abc.fr/>

Application **Laravel 12 + Vue 3 (Inertia, SSR optionnel) + PostgreSQL/PostGIS**,
migrée depuis un ancien serveur **Node/TypeScript/SQLite**.

- `app/` — le vrai code : Collectors, Services, Models, Commands artisan, Controllers.
- `resources/js/` — front Vue 3 (Pages Inertia, Components).
- `routes/web.php` (Inertia/HTML), `routes/api.php` (contrats compatibles avec
  l'ancien serveur Node), `routes/console.php` (scheduler).
- `config/abc.php` — config métier : URLs des sources CSV, seuils d'anomalie,
  durée ABC, compte admin seedé.
- `server/` et `src/` — **ancienne application Node (legacy)** conservée comme
  filet de secours et source de vérité des contrats. **Ne pas modifier** sans
  comprendre que la parité API (`tests/Feature/GeoJsonParityTest.php`) repose
  dessus.
- `data/abc.db` — base SQLite historique, source de `abc:import-legacy`.
- `dist/` — sortie de l'ancien build TS (obsolète côté Laravel).

## Stack & versions

- PHP 8.3+ (extensions : `pdo_pgsql`, `pgsql`, `intl`, `mbstring`)
- Laravel 12, Inertia 2, spatie/laravel-permission, league/csv, ziggy
- Node 20+ (24 en CI), Vite 8, Vue 3, Bootstrap 5, Leaflet
- PostgreSQL 16 + PostGIS 3.4
- Tests : PHPUnit 12 (`phpunit.xml`, SQLite en mémoire). Style : Laravel Pint.
- CI GitHub Actions : `.github/workflows/ci.yml` (build Vite) et `tests.yml`.

## Développement local — Docker (recommandé, sans WAMP)

```bash
docker compose up -d app          # app + PostGIS : http://localhost:8000
docker compose --profile build run --rm assets   # build Vite (npm run build)
docker compose --profile hmr up vite             # dev avec HMR (port 5173)
docker compose run --rm app php artisan test     # PHPUnit
docker compose down               # les volumes (DB, vendor, node_modules) persistent
```

Après un arrêt du conteneur Vite (`--profile hmr`), supprimer `public/hot`
s'il traîne : sinon le site sert des assets pointant vers le serveur HMR
éteint et affiche une page blanche.

Au premier démarrage, `docker/php/entrypoint.sh` fait tout seul : `composer
install` (volume `abc_vendor`), `APP_KEY` si absente, `migrate`, `db:seed`
(admin depuis `ADMIN_*` du `.env`), puis `abc:import-legacy` si la table
`projets` est vide. Les variables `DB_HOST=postgis` / `DB_PORT=5432` sont
forcées par docker-compose (elles priment sur `.env`, qui peut donc rester
pointé vers `127.0.0.1:5433` pour un usage hors Docker).

Commandes ponctuelles utiles :

```bash
docker compose exec app php artisan abc:status
docker compose exec app composer pint          # style
```

## Commandes artisan métier

| Commande | Rôle |
| --- | --- |
| `abc:collect [--init]` | Collecte des 4 sources (data.gouv, Wayback, Fonds vert 2024/2025), puis statuts, géocodage, anomalies. Sans `--init` : purge via `session_replication_role = replica` (superuser requis). Avec `--init` : upsert sans purge. |
| `abc:import-legacy` | Importe `data/abc.db` (SQLite) vers PostgreSQL (une seule fois). |
| `abc:status` | Recalcule statuts + anomalies. |
| `abc:geocode` | Géocode les communes manquantes (geo.api.gouv.fr). |
| `abc:export --fmt=csv\|geojson` | Export vers `storage/app/abc/exports`. |
| `abc:export-deploy` | Export SQL portable sans `geom` (hébergements sans PostGIS). |
| `abc:verify` | Worklist de vérification (CSV). |
| `abc:backup` | pg_dump avec rotation (14, `config('abc.backup_retention')`). |
| `ssr:watchdog [--restart]` | Maintient le serveur SSR Inertia en vie (cron chaque minute). |

## Invariants critiques (ne jamais casser)

1. **Le collect ne touche jamais le travail humain** : tables
   `verifications`, `contributions`, `audit_log` — seules `projets`,
   `communes`, `snapshots` sont purgées puis ré-ingérées.
2. **Parité API avec l'ancien serveur Node** : `/api/abc.geojson`, `/api/meta`,
   etc. Garantie par `tests/Feature/GeoJsonParityTest.php`.
3. **Exclusions** : un projet supprimé par un admin va dans `project_exclusions`
   et n'est **pas ré-importé** au collect tant que l'exclusion tient.
4. **SEO** : slugs uniques sur `projets`/`actualites` (routes `abc/{projet:slug}`,
   `actualites/{actualite:slug}`), pages commune/département/région,
   `sitemap.xml` (`SitemapController`), `robots.txt`. Toute nouvelle page
   publique doit être ajoutée au sitemap.
5. **RGPD** : la mesure d'audience (Matomo/GTag) ne se charge **qu'après
   consentement** (`ConsentBanner.vue`, `config/seo.php`).

## Conventions

- PHP : PSR-12, 4 espaces, ordre alphabétique des imports ; vérifier avec
  `vendor/bin/pint` avant de committer.
- Vue : `<script setup>` en général, ressources JS dans `resources/js`.
- Nommage artisan : commandes métier préfixées `abc:`.
- Pas de division CRLF (`eol=lf` global dans `.gitattributes`).
- Messages de commit : préfixes `feat:`, `fix:`, docs en français accepté.
- Utiliser le français dans l'UI et les textes visibles (site public français).

## Tests

```bash
php artisan test            # ou : vendor/bin/phpunit
vendor/bin/pint --test      # style sans écriture
```

Suites : `tests/Unit` (AnomalyService, StatusService, ProjectIdService,
CsvReaderService) et `tests/Feature` (collect, flux contribution, exclusions,
SEO landing pages, parité GeoJSON, watchdog SSR). PHPUnit utilise SQLite
`:memory:` — écrire les tests sans dépendre de PostGIS quand c'est possible.
