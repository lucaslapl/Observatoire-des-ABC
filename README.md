# Observatoire des ABC

Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des
projets ABC financés (data.gouv / Fonds vert), archives 2022 (Wayback Machine),
vérifications manuelles, contributions du public et export cartographique.
Site public : <https://observatoire-abc.fr/>

## Présentation

L'Observatoire des ABC recense et met à jour l'ensemble des projets Atlas de
la Biodiversité Communale portés par les collectivités françaises. Il agrège
plusieurs sources publiques (registre national des ABC sur data.gouv.fr, lots
biodiversité du Fonds vert 2024 et 2025, archives 2022 via la Wayback Machine),
en dérive des fiches riches — statuts, porteurs, années, communes — et publie
le tout sur une carte interactive où chaque projet est vérifiable, corrigeable
et commentable par les humains.

La philosophie du projet tient en un principe : **la machine collecte, mais ne
touche jamais le travail humain**. Les collectes automatiques synchronisent les
données des sources en ligne par upserts ciblés ; les vérifications, les
contributions et le journal d'audit ne sont jamais purgés ni écrasés. Un
projet disparu des sources mais porteur d'un travail humain est conservé ;
un projet supprimé par un administrateur est exclu des collectes futures tant
que l'exclusion n'est pas levée explicitement.

Le jeu de données de référence aboutit à **1263 projets, 5421 lignes communes,
1958 snapshots** (export GeoJSON = 5420 points), attributs paritaires avec
l'ancien endpoint de l'application historique.

## Fonctionnalités

- **Carte Leaflet** de tous les projets ABC : marqueurs simples, marqueurs
  intercommunaux et marqueurs agrégés (plusieurs ABC au même point, réduits
  pour éviter la confusion), communes reliées par des traits au clic.
- **Pages publiques indexables** : fiche d'un projet (`/abc/{slug}`), page
  commune (`/commune/{code}`), page département (`/departement/{code}`), page
  région (`/region/{slug}`), fil d'actualités (`/actualites`), mentions légales
  et politique de confidentialité.
- **Signalement de correction** (public) : depuis le popup de la carte, chaque
  visiteur peut proposer une correction ; la contribution est modérée par un
  administrateur ou un modérateur avant publication.
- **Espace membre** : inscription ouverte avec vérification d'email
  obligatoire, rôle `contributeur` automatique, page profil (contributions
  liées au compte, mot de passe, suppression du compte). Les contributions
  d'un compte supprimé sont conservées et anonymisées — le travail humain
  n'est jamais perdu.
- **Worklist de vérification** (`/verify`) : file « À vérifier » avec filtres
  *Vérifiés* / *Tous*, pour confirmer ou corriger les projets un par un.
- **Exclusions administrateur** : suppression d'un projet depuis la carte avec
  motif optionnel consigné dans le journal d'audit ; le projet n'est pas
  ré-importé au collect tant que l'exclusion tient. Levée d'exclusion depuis
  le panneau admin avec ré-import au prochain collect.
- **Exports** : CSV et GeoJSON (`storage/app/abc/exports`), export SQL portable
  sans `geom` pour les hébergements sans PostGIS (`abc:export-deploy`), export
  du journal d'audit en CSV.
- **Administration** : modération des contributions et actualités, collecte
  manuelle, sauvegardes immédiates, gestion des exclusions.
- **RGPD** : mesure d'audience (Matomo/GTag) chargée uniquement après
  consentement explicite (bannière dédiée).

## Sources de données

| Source | Contenu |
| --- | --- |
| data.gouv.fr (registre OFB) | Projets ABC financés, ré-ingérés par upsert à chaque collect ; les URLs CSV sont re-résolues via l'API data.gouv à chaque collect pour suivre la dernière révision (repli sur l'URL pinnée de `config/abc.php`, désactivable via `SOURCES_AUTO_RESOLVE=false`) |
| Fonds vert — lot biodiversité (P113) | 65 projets ABC (16 en 2024, 49 en 2025). L'export général du Fonds vert ne contient que des faux positifs « ABC » (bâtiments, etc.) et n'est pas utilisé |
| Wayback Machine | Archives 2022 du registre, pour l'historique des projets |
| `data/abc.db` | Base SQLite historique (140 projets Fonds vert issue d'une version plus riche des exports, indisponible aujourd'hui), importée une seule fois via `abc:import-legacy` |
| geo.api.gouv.fr | Géocodage des communes manquantes |

Garde-fous du collect : sources HTML refusées, suppression massive refusée
sans `--force`, suppression ciblée uniquement des projets disparus des sources
ET sans vérifications ni contributions. Aucun superuser PostgreSQL n'est
requis (l'ancienne purge via `session_replication_role = replica` a été
supprimée).

## Stack technique

- **Backend** : PHP 8.3+, Laravel 12, Inertia 2 (SSR optionnel),
  spatie/laravel-permission, league/csv, ziggy.
- **Frontend** : Node 20+, Vite, Vue 3 (`<script setup>`), Bootstrap 5,
  Leaflet. `npm run build` produit deux bundles : le client (`public/build`)
  et le SSR (`bootstrap/ssr`).
- **Base** : PostgreSQL 16 + PostGIS 3.4 ; le schéma saute la colonne `geom`
  si l'extension est absente (mutualisé compatible).
- **Tests** : PHPUnit 12 (SQLite en mémoire), Laravel Pint pour le style,
  `node --test` pour les modules front purs (`resources/js/map/`, `utils/`).
- **CI** : GitHub Actions — `.github/workflows/tests.yml` (PHP 8.3, build Vite,
  PHPUnit, Pint) et `ci.yml` (Composer + build Vite sous Node 24).
- Application **migrée depuis un ancien serveur Node/TypeScript/SQLite** ; la
  parité des contrats API avec l'ancien serveur est garantie par
  `tests/Feature/GeoJsonParityTest.php` (fixtures autonomes).

## Architecture du code

- `app/` — Collectors, Services, Models, Commands artisan, Controllers.
- `resources/js/` — front Vue 3 : Pages Inertia, Components, modules `map/`
  (constantes, builders de popup, export d'image).
- `routes/web.php` — pages Inertia/HTML ; `routes/api.php` — contrats API
  compatibles avec l'ancien serveur Node ; `routes/console.php` — scheduler
  (backup quotidien à 04:00, collect mensuel le 1er à 03:00).
- `config/abc.php` — config métier : URLs des sources CSV, seuils d'anomalie,
  durée ABC, compte admin seedé, rotation des sauvegardes.
- `config/seo.php` — mesure d'audience conditionnée au consentement.

## Commandes artisan

| Commande | Rôle |
| --- | --- |
| `abc:collect` | Collecte des 4 sources puis statuts, géocodage, anomalies (synchronisation par upsert + suppression ciblée) |
| `abc:import-legacy` | Importe `data/abc.db` (SQLite) vers PostgreSQL (une seule fois) |
| `abc:status` | Recalcule les statuts et les anomalies |
| `abc:geocode` | Géocode les communes manquantes (geo.api.gouv.fr) |
| `abc:export --fmt=csv\|geojson` | Export CSV ou GeoJSON (`storage/app/abc/exports`) |
| `abc:export-deploy` | Export portable (SQL, sans `geom`) pour hébergement sans PostGIS |
| `abc:verify` | Worklist de vérification (CSV) |
| `abc:backup` | Sauvegarde pg_dump avec rotation (14), copie distante optionnelle |
| `ssr:watchdog [--restart]` | Maintient le serveur SSR Inertia en vie (cron chaque minute) |

## Rôles & permissions

Rôles Spatie (`db:seed`) : `admin`, `moderateur`, `contributeur`.

- `admin` : panneau complet (modération, collecte, sauvegarde, actualités,
  exclusions + export du journal d'audit), suppression de projets depuis la
  carte.
- `moderateur` : modération des contributions et actualités.
- `contributeur` : compte connecté (proposition de données).

Le compte administrateur est créé depuis `.env` (`ADMIN_USERNAME`,
`ADMIN_EMAIL`, `ADMIN_PASSWORD`) par `db:seed`. Connexion au panneau via
`/login`.

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

## Référencement (SEO)

Le site est pensé pour être indexé : rendu serveur (SSR) de toutes les pages,
URLs hiérarchiques, données structurées JSON-LD et sitemap.

| URL | Rôle SEO |
| --- | --- |
| `/` | Page d'accueil (carte + contenu indexable : stats, régions, départements) |
| `/abc/{slug}` | Fiche d'un projet ABC (communes, porteur, statut, source) |
| `/commune/{code}` | Page commune (projets ABC la concernant) |
| `/departement/{code}` | Page département (projets et communes) |
| `/region/{slug}` | Page région (départements du territoire) |
| `/actualites` / `/actualites/{slug}` | Fil d'actualités et articles |
| `/mentions-legales` / `/confidentialite` | Pages légales (RGPD) |
| `/sitemap.xml` | Sitemap (6 000+ URLs, généré et caché, `lastmod` des projets) |
| `/robots.txt` | Disallow `/api/`, `/admin`, `/login`, `/verify` + pointe vers le sitemap |

Les pages fonctionnelles (`/verify`, `/login`, `/admin`) sont en `noindex`.
Toute nouvelle page publique doit être ajoutée au sitemap.

## Tests & style

```bash
php artisan test                 # PHPUnit (slug, statuts, anomalies, CSV, contributions, GeoJSON, exclusions)
vendor/bin/pint --test           # style sans écriture
npm run test:js                  # modules front purs (node --test)
npm run build                    # compilation frontend (Vite)
```

## Licence

**AGPL-3.0** (GNU Affero General Public License v3) — voir `LICENSE`.

Le code de l'Observatoire est libre : vous pouvez l'utiliser, l'étudier, le
modifier et le redistribuer, à condition de publier les sources des versions
modifiées mises à disposition en ligne (section 13, « Remote Network
Interaction »). Les données restent sous la licence de leurs producteurs
(data.gouv.fr, Licence Ouverte).
