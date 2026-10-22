# AGENTS.md — Observatoire des ABC

## Qu'est-ce que ce projet

Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des
projets ABC financés (data.gouv / Fonds vert), archives 2022 (Wayback Machine),
vérifications manuelles, contributions du public, carte Leaflet et export
CSV/GeoJSON. Site public : <https://observatoire-abc.fr/>

Application **Laravel 12 + Vue 3 (Inertia, SSR optionnel) + PostgreSQL/PostGIS**,
migrée depuis un ancien serveur **Node/TypeScript/SQLite**.

- `app/` — le vrai code : Collectors, Services, Models, Commands artisan, Controllers.
- `resources/js/` — front Vue 3 (Pages Inertia, Components, modules `map/`).
- `resources/js/map/` — constantes, builders de popup et export d'image,
  testés par `tests/js/*.test.mjs` (`npm run test:js`).
- `routes/web.php` (Inertia/HTML), `routes/api.php` (contrats compatibles avec
  l'ancien serveur Node), `routes/console.php` (scheduler).
- `config/abc.php` — config métier : URLs des sources CSV (pinnées, re-résolues
  via l'API data.gouv à chaque collect), seuils d'anomalie, durée ABC, compte
  admin seedé, commande de copie des sauvegardes.
- `data/abc.db` — base SQLite historique, source de `abc:import-legacy`.

> L'ancienne application Node (`server/`, `src/`, `dist/`, tsconfig) a été
> **retirée du dépôt**. La parité des contrats API est garantie par
> `tests/Feature/GeoJsonParityTest.php` (fixtures autonomes).

## Stack & versions

- PHP 8.3+ (extensions : `pdo_pgsql`, `pgsql`, `intl`, `mbstring`)
- Laravel 12, Inertia 2, spatie/laravel-permission, league/csv, ziggy
- Node 20+ (24 en CI), Vite 8, Vue 3, Bootstrap 5, Leaflet
- PostgreSQL 16 + PostGIS 3.4
- Tests : PHPUnit 12 (`phpunit.xml`, SQLite en mémoire). Style : Laravel Pint.
- CI GitHub Actions : `.github/workflows/ci.yml` (build Vite, tests PHPUnit
  et JS, Pint, PHPStan niveau 5).

## Développement local — Docker (seul environnement utilisé, plus de WAMP)

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
forcées par docker-compose et priment sur le `.env`.

Commandes ponctuelles utiles :

```bash
docker compose exec app php artisan abc:status
docker compose exec app vendor/bin/pint      # style (le script composer "pint" n'existe pas)
```

### Emails en local : Mailpit

`docker compose up -d` démarre aussi **Mailpit** (boîte SMTP locale) : les
emails de l'app (vérification d'email, reset de mot de passe) partent vers
`mailpit:1025` (variables `MAIL_*` forcées par docker-compose) et se lisent
dans l'interface **http://localhost:8025**. En production, configurer un
SMTP réel dans `.env` (`MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION=tls`,
`MAIL_USERNAME`, `MAIL_PASSWORD`).

### Espace membre

Inscription ouverte (`/register`) avec vérification d'email obligatoire
(`Illuminate\Contracts\Auth\MustVerifyEmail`), rôle `contributeur` attribué
automatiquement. Espace membre : `/profil` (contributions, mot de passe,
suppression du compte). Les contributions liées à un compte (colonne
`contributions.user_id`, nullable) sont conservées et anonymisées si le
compte est supprimé — le travail humain n'est jamais perdu.

## Commandes artisan métier

| Commande | Rôle |
| --- | --- |
| `abc:collect` | Collecte des 4 sources (data.gouv, Wayback, Fonds vert 2024/2025), puis statuts, géocodage, anomalies. **Synchronisation** : upserts + suppression ciblée des projets disparus sans travail humain — aucun superuser requis. Garde-fous : sources HTML refusées, suppression massive refusée sans `--force`. |
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
   `verifications`, `contributions`, `audit_log`. La synchronisation
   (`CollectService::deleteStaleProjects`) supprime uniquement les projets
   disparus des sources ET sans verifications/contributions.
2. **Parité API avec l'ancien serveur Node** : `/api/abc.geojson`, `/api/meta`,
   etc. Garantie par `tests/Feature/GeoJsonParityTest.php`.
3. **Exclusions** : un projet supprimé par un admin va dans `project_exclusions`
   et n'est **pas ré-importé** au collect tant que l'exclusion tient.
4. **SEO** : slugs uniques sur `projets`/`actualites` (routes `abc/{projet:slug}`,
   `actualites/{actualite:slug}`), pages commune/département/région,
   `sitemap.xml` (`SitemapController`), `robots.txt`. Toute nouvelle page
   publique doit être ajoutée au sitemap.
5. **RGPD** : la mesure d'audience Matomo (anonyme, exemption mesure
   d'audience) se charge **par défaut** et reste révocable (bannière, lien
   « Cookies ») (`ConsentBanner.vue`, `config/seo.php`).

## Accès à la production — ne jamais requêter le site en rafale

La production est protégée par Imunify360 (hébergeur) : une rafale de
requêtes (~100 en 30 s) déclenche le **blocage automatique de l'IP
émettrice** — le site semble alors « indisponible » alors qu'il ne l'est
que pour cette IP (incident du 2026-10-22, une boucle curl de vérification
ayant bloqué l'IP du poste de travail). Règles :

- Vérifier en local autant que possible (Docker, `http://localhost:8000`).
- **Jamais de boucle curl/wget sur les pages de production** : au plus
  quelques pages clés, espacées (`sleep 1` entre chaque requête).
- Si le site semble down depuis le poste : tester en 4G avant tout ticket —
  c'est le plus souvent l'IP qui est bloquée, pas le site.

## Conventions

- PHP : PSR-12, 4 espaces, ordre alphabétique des imports ; vérifier avec
  `vendor/bin/pint` avant de committer.
- Vue : `<script setup>` en général, ressources JS dans `resources/js`.
- Nommage artisan : commandes métier préfixées `abc:`.
- Pas de division CRLF (`eol=lf` global dans `.gitattributes`).
- Messages de commit : préfixes `feat:`, `fix:`, docs en français accepté.
- Utiliser le français dans l'UI et les textes visibles (site public français).

## Déploiement — information obligatoire à chaque push

Chaque push doit indiquer **toutes les étapes nécessaires à la mise à jour
en production** (dans le message de commit ou le résumé du push), en les
distinguant de ce qui ne l'est pas. Exemples d'étapes à couvrir :

- `composer install --no-dev --optimize-autoloader` si `composer.json` a changé ;
- `npm ci && npm run build` (client + bundle SSR) si le front ou les
  dépendances JS ont changé ;
- `php artisan migrate --force` si des migrations sont ajoutées ;
- relance du serveur SSR (`php artisan ssr:watchdog --restart`) si le front
  ou le SSR a changé ;
- caches à rafraîchir/vider, variables `.env` nouvelles, seed, commandes
  artisan à rejouer (`abc:status`, etc.), dépendances système (extension PHP,
  Node).

Si aucun changement de déploiement n'est requis, l'écrire explicitement
(« aucun changement de déploiement »). Le guide de déploiement complet n'est
pas dans le README (présentation du projet uniquement) : reporter les étapes
dans le commit/le push.

## Tests

```bash
php artisan test            # ou : vendor/bin/phpunit
vendor/bin/pint --test      # style sans écriture
```

Suites : `tests/Unit` (AnomalyService, StatusService, ProjectIdService,
CsvReaderService, DataGouvResolver, GeocodeService, BackupService,
ExportService) et `tests/Feature` (collect/sync, flux contribution,
exclusions, SEO landing pages, parité GeoJSON, cache HTTP, throttle login,
watchdog SSR). PHPUnit utilise SQLite `:memory:` — écrire les tests sans
dépendre de PostGIS quand c'est possible.

Front : `npm run test:js` (node --test, modules purs de `resources/js/map/`
et `utils/`). Dans Docker :
`docker compose --profile build run --rm --no-deps assets node --test tests/js/*.test.mjs`.
