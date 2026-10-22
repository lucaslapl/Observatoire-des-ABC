# Changelog

Toutes les évolutions notables de ce projet sont documentées dans ce fichier.
Le format s'inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Audit du 2026-10-21 — phase 2 : financements Fonds vert et refonte du collector

- **Nouvelle table `financements`** (migration réversible) : une ligne
  Fonds vert P113 est désormais un FINANCEMENT accordé à un ABC existant
  (source, bénéficiaire, année, montant, ligne brute JSON ; unicité sur
  project_id + source + ligne_id). Le dump SQL de secours l'embarque.
- **`FondsVertCollector` refondu : il ne crée plus JAMAIS de projet.**
  Chaque ligne candidate ABC est rattachée par clé d'identité exacte,
  puis via les alias de fusion (project_aliases), puis par nom normalisé
  unique. Correspondance ambiguë (plusieurs projets au même nom) ou
  nouvelle : entrée « doublon suspect »/« à vérifier » en worklist —
  aucune création silencieuse. Le rattachement marque le projet comme
  ingéré (protection de la suppression ciblée).
- **Correctif filtre ABC (bug historique d'accents)** : le motif
  `biodiversit[ée]` sans normalisation NFC ni drapeau `/u` ne matchait
  jamais le « é » UTF-8 — le pipeline était aveugle aux lignes écrites
  « Atlas de la biodiversité communale » sans le sigle « ABC ». C'est la
  racine des ~75 candidats Fonds vert non ingérés identifiés par l'audit.
- **Suppression ciblée renforcée** : un projet financé ne peut plus être
  supprimé comme « disparu des sources » tant que son financement est
  rattaché.
- **Worklist élargie** : la page `/verify` affiche une section
  « Candidats Fonds vert non rattachés » (nom, bénéficiaire, millésime,
  montant) ; une ligne rattachée plus tard en disparaît automatiquement.
  Le CSV `abc:verify` les inclut aussi. Journalisation dans audit_log
  (action `worklist_fondsvert`, idempotente par ligne).
- **Collect réel vérifié en local** (registre 20260709, FV 2024 rév.
  20250731, FV 2025 rév. 20260728) : 140 candidats ABC (75 + 65),
  **0 projet créé**, 65 financements rattachés aux projets Fonds vert
  connus, 75 lignes en file (58 nouvelles + 17 ambiguës, conforme aux
  75 candidats non ingérés de l'audit). Collect rejoué : strictement
  aucun changement (idempotence).
- **Tests** : `FondsVertCollectorTest` (10 cas : rattachement par id,
  alias, nom, nouveau, ambigu, accents NFC/NFD, idempotence, worklist
  page/CSV, auto-résolution, protection stale). Suite : 168 verts.

### Audit du 2026-10-21 — phase 1 : alias, journal de fusion et commande de fusion

- **Nouvelle table `project_aliases`** (migration réversible) : un doublon
  fusionné y conserve son id (`alias_project_id`), son slug (`alias_slug`),
  sa source et le motif de fusion, rattachés au projet canonique
  (`project_id`). Le journal existant `audit_log` est réutilisé pour tracer
  chaque fusion (action `fusion`, états avant/après en JSON).
- **Nouvelle commande `abc:merge-projects {doublon} {canonique} {--motif=}`** :
  union des communes (dédupliquée sur le code INSEE, la valeur du canonique
  gagne les conflits), union des snapshots (dédupliquée sur la date),
  migration des vérifications, contributions et enrichissements (notes
  concaténées, jamais perdues), création de l'alias, suppression du doublon,
  journalisation. Un snapshot JSON de l'état complet du doublon est écrit
  avant fusion dans `storage/app/abc/merges/` (rollback possible). Refus de
  fusionner un alias, de fusionner dans un alias, ou deux fois le même id.
- **Redirection 301 des anciens slugs** : la route `/abc/{slug}` est résolue
  manuellement ; un slug d'alias redirige en 301 vers la page du canonique,
  un slug inconnu reste un 404. Aucune page fusionnée ne casse.
- **Le collect ne réintroduit pas un projet fusionné** :
  `ProjetRepository::isAliased` ignore, comme les exclusions, tout id
  présent dans `project_aliases` (upsert et snapshots).
- **Worklist /verify** : un projet portant une vérification
  `etat=a_verifier` apparaît désormais dans la page /verify et le CSV
  `abc:verify`, même sans flag (critère explicite de mise en file).
- **Scripts de triage versionnés** (`data/triage/*.csv`, extraits du
  tableur fourni par `scripts/extract-triage.php`) :
  48 paires « Fusionner » (canonique = registre OFB, copie wayback/Fonds
  vert en alias) et 26 paires « À vérifier ».
- **Application sur la base locale** (base = copie de production) :
  48 fusions via `scripts/apply-merges.php` (validation préalable complète,
  refus des chaînes et des doublons d'id) : projets 1 263 → 1 215
  (-48 exactement), lignes projet-commune 5 421 → 5 274, snapshots
  2 200 → 2 199, 48 alias créés, 8 vérifications et 6 contributions
  conservées (migrées), 48 entrées `audit_log` `fusion`. Les 26 paires
  « À vérifier » sont inscrites en worklist via
  `scripts/queue-doublons-verification.php` (idempotent : 52 projets
  listés dans /verify, notes de paires journalisées, jamais de perte des
  états humains existants). Les 11 séries d'éditions et les décisions
  « Conserver distincts » n'ont pas été touchées.
- **Tests** : `MergeProjectsCommandTest` (6 cas : unions, alias, 301,
  refus, anti-réimport, worklist). Suite : 157 tests verts.

### Audit du 2026-10-21 — phase 0 : environnement et état de référence

- **Base locale = copie exacte de la production** : un dump `pg_dump`
  (format custom) de la base de production, fourni le 2026-10-22, a été
  vérifié (restauration dans une base éphémère : 1 263 projets,
  5 421 lignes projet-commune, 2 200 snapshots, 8 vérifications,
  6 contributions, répartition par source conforme à l'audit :
  data.gouv 1 112 / wayback 86 / FV 2024 16 / FV 2025 49), puis la base
  locale `abc` a été réinitialisée dessus. L'ancien état local reste
  récupérable via la sauvegarde vérifiée `abc-2026-10-22-08-36-33.sql`
  (1 263 projets, 5 421 communes, 3 070 snapshots, 6 vérifications,
  3 contributions). Le dump de référence est conservé hors rotation dans
  `data/backups/production-reference-2026-10-22.dump` (non versionné :
  contient des données personnelles).
- **Fait constaté** : la production n'a PAS la colonne PostGIS
  `communes.geom` (hébergement sans PostGIS) ; le code ne l'utilise que
  sous garde (`lon`/`lat` dans `GeoJsonService`). La base locale restaurée
  sur le schéma production n'a donc pas non plus `geom`.
- **Sauvegarde pré-phase vérifiée restaurable** : `abc:backup` (format SQL,
  `pg_dump` indisponible dans le conteneur), puis restauration complète dans
  une base éphémère `abc_restore_test` (migrations + import du dump) :
  compteurs identiques à la base locale avant suppression de la base de test.
- **Aucun collect automatique possible en local** : `COLLECT_AUTOMATIC=false`
  ajouté explicitement au `.env` local (défaut déjà `false` dans
  `config/abc.php`) ; `php artisan schedule:list` ne planifie que
  `abc:backup` et `ssr:watchdog`, jamais `abc:collect`.
- **`abc:import-legacy` compatible SQLite (tests)** : le rétro-remplissage
  de la colonne PostGIS `geom` est désormais ignoré hors PostgreSQL /
  sans PostGIS (garde driver + colonne), permettant de rejouer l'import
  dans les tests PHPUnit (SQLite `:memory:`).
- **Test de référence** (`tests/Feature/ReferenceDatasetTest.php`) :
  compteurs exacts après `abc:import-legacy` (1 338 projets, 5 437 lignes
  projet-commune, 1 958 snapshots, 5 vérifications, 1 contribution) et
  absence du collect dans le planning tant que `COLLECT_AUTOMATIC=false`.

### Corrigé
- **Incident du 2026-10-19 (production) — `abc:collect` supprimait les projets
  aux données inchangées** : la suppression ciblée détectait les « disparus
  des sources » via `updated_at < début du collect`, or un upsert sans
  changement de données ne rafraîchit PAS `updated_at` (comportement
  d'Eloquent). ~1000 projets ont été supprimés en production avant
  restauration depuis la sauvegarde. Nouvelle détection fondée sur les ids
  réellement vus dans les sources (`ProjetRepository::markIngested`), y
  compris les projets wayback déjà connus (le collector n'upsert que les
  nouveaux).
- **Garde-fous** : une source répondant une page HTML (ex. archive.org en
  rate-limit) est refusée et jamais mise en cache (`DownloadService`) ; une
  suppression ciblée portant sur plus de la moitié des projets est refusée
  sauf `--force` ; un collect sans aucun projet ingéré est refusé.
- Tests de régression pour ces trois cas (CollectServiceTest,
  DownloadServiceTest).

### Ajouté
- **Résolution automatique des sources data.gouv** (`DataGouvResolver`) : à
  chaque collect, les URLs CSV du registre ABC et du Fonds vert sont
  re-résolues via l'API data.gouv pour suivre la dernière révision publiée,
  avec repli sur l'URL pinnée. Désactivable : `SOURCES_AUTO_RESOLVE=false`.
- **Copie distante des sauvegardes** : `BACKUP_UPLOAD_CMD` exécuté après
  chaque `abc:backup` (`{file}` = chemin du dump, ex. rclone/scp).
- **Analyse statique** : PHPStan/Larastan niveau 5 (0 erreur), ajoutée à la CI.
- **Tests front** (`npm run test:js`, node --test sans dépendance) pour les
  modules purs extraits de la carte et de l'admin.
- **Tests PHP** : DataGouvResolver, GeocodeService, BackupService (rotation,
  upload), ExportService, synchronisation du collect (suppression ciblée).
- **Docker** : profils `ssr` (rendu serveur Inertia) et `scheduler`
  (reproduit le CRON de production) ; `INERTIA_SSR_URL` configurable.
- **Développement local 100 % Docker** : `docker compose up -d app` (PHP 8.3,
  PostGIS 16), profils `build` (assets Vite) et `hmr` (hot reload), entrypoint
  automatique (composer, `APP_KEY`, migrations, seed, import legacy si base
  vide). Plus besoin de WAMP (`docker/`, `docker-compose.yml`, `AGENTS.md`).
- **AGENTS.md** : contexte projet, commandes, invariants critiques, conventions.
- **Modération : action « Annuler »** sur les contributions en attente
  (`POST /api/admin/contributions/{id}/annuler`) : statut `annulee` (erreur
  d'envoi / erreur de l'auteur), distinct de « Refuser » (information
  erronée, statut `refusee`) ; journalisé dans `audit_log`.
- **Espace admin : liste des comptes** créés (nom, email, rôles, email vérifié,
  nombre de contributions, date), prop `comptes` de `/admin`.

### Changé
- **`abc:collect` : synchronisation sans superuser** — la purge via
  `session_replication_role = replica` (réservée aux superuser Postgres) est
  remplacée par des upserts + une suppression ciblée des projets disparus des
  sources ET sans travail humain (verifications/contributions). Le collect
  tourne désormais sur tout hébergement mutualisé. `--init` devient obsolète
  (accepté pour compatibilité).
- **Front** : `MapExplorer.vue` (1104 → ~930 lignes) et `Admin.vue` réduits —
  logique extraite dans des modules testés (`resources/js/map/` :
  constantes, popupBuilders, exportImage ; `utils/contributionDisplay.js`).
  **html2canvas/jspdf (~700 Ko) chargés uniquement à l'export** (import
  dynamique à la demande) au lieu du montage de la carte.
- **Scheduler** : `abc:backup` et `abc:collect` en arrière-plan
  (`runInBackground`, `withoutOverlapping`, `onOneServer`) — le cron
  n'est plus bloqué pendant un dump ou un collect.
- **Dépendances** : 5 avis de sécurité corrigés (laravel/framework XSS debug
  page, league/commonmark ×3, league/flysystem, symfony/http-foundation) ;
  `composer audit` est propre.
- **`GET /api/abc.geojson` : cache HTTP** — ETag (état des tables
  `projets`/`communes`), `Cache-Control: public, max-age=3600`, réponse 304
  immédiate si l'ETag correspond (~4 Mo économisés par visite répétée).
- **`/api/meta` : 5 requêtes SQL sur `projets` regroupées en une seule**
  (agrégats conditionnels), sortie strictement identique (parité API).

### Sécurité
- **Anti brute-force sur `/login`** : rate limit 5 essais/min par couple
  email+IP et 20/min par IP (`throttle:login`).
- **`GET /api/contributions` ne publie que les contributions `validee`** ;
  les contributions `en_attente`/`refusee` restent visibles côté admin.
- **Contributions réservées aux comptes vérifiés** : `POST /api/contributions`
  exige désormais `auth` + `verified` (invité : 401, email non vérifié : 403).
  Depuis la carte, un visiteur non connecté est redirigé vers
  `/register?raison=contribution` avec un message explicatif ; un compte non
  vérifié est invité à vérifier son email.
- **`TRUST_PROXY=1`** derrière un reverse proxy : `request()->ip()` lit
  l'IP réelle (`X-Forwarded-For`) et le schéma https est respecté.
- **`COOKIE_SECURE=1`** en production : cookie de session en `Secure`.

### Supprimé (legacy Node)
- `src/`, `server/`, `dist/`, `tsconfig*.json`, `scripts/start-*.sh` et
  `scripts/stop-server.sh` : l'ancienne application Node/TS est retirée du
  dépôt. `data/abc.db` (données historiques) et les tests de parité restent
  la source de vérité. Les `ExampleTest` du squelette Laravel sont supprimés.

### Hygiène du dépôt
- CI : un seul workflow (jobs `php` tests + style + PHPStan, `node` build) ;
  tests JS ajoutés ; référence à la branche obsolète `laravel-migration` supprimée.
- `data/cache/` (16 Mo de CSV hérités) retiré du suivi git (régénérable par le
  collect ; `data/abc.db` reste suivi, source de `abc:import-legacy`).
- **Licence du projet : AGPL-3.0** (GNU Affero GPL v3) — alignement de
  `LICENSE` (texte officiel), `composer.json`, `package.json` et `README`,
  jusqu'alors incohérents (MIT / GPL-3.0 / « tous droits réservés »).
  Les données sources restent sous la Licence Ouverte de data.gouv.fr.

### À venir
- En réflexion.

## [0.2.0] — 2026-08-11

### Ajouté
- Renommage en **Observatoire des ABC** (interface, package npm `observatoire-des-abc`,
  user-agent, docs).
- **Contributions ouvertes** : la page `/verify` est lisible par tous, avec un bouton
  « 💡 Signaler une correction » (statut / note / lien / autre info). Chaque suggestion
  reçoit un statut `en_attente` et n'est appliquée qu'après validation par un admin.
- **Panneau d'administration `/admin`** protégé par mot de passe (scrypt + sel, sessions
  en base) : liste des contributions avec **adresse IP conservée**, user-agent et
  horodatage ; actions **valider** (→ applique le verdict sur la carte), **refuser**,
  **retirer (rollback)**.
- Table d'**audit** (`audit_log`) : chaque action admin est tracée avec l'état avant/après
  → retour en arrière fiable.
- Les **verdicts directs** de l'admin (`POST /api/verifications`) passent derrière la
  session admin (plus de modification par des visiteurs anonymes).
- Badge « 📥 N suggestions » sur la carte (via `/api/meta`).
- Sauvegardes : `npm run backup` (checkpoint WAL + copie horodatée de la base, rotation
  sur 14 exemplaires) — à planifier en CRON.
- Build de production : `npm run build` (TypeScript → `dist/`), `npm run start`,
  `scripts/start-prod.sh`.
- Chargement de l'environnement via `.env` (dotenv) + modèle `.env.example`.

### Corrigé
- Résolution de la racine du projet en build : `ROOT = process.cwd()` au lieu de
  `__dirname` (les fichiers statiques et `data/` étaient cherchés dans `dist/` après
  compilation).

### Sécurité
- Login admin rate-limité (5 tentatives / 15 min / IP) ; contributions rate-limitées
  (10 / heure / IP).
- Mot de passe admin jamais stocké en clair : uniquement en variable d'environnement,
  comparé via scrypt.
- Cookies de session `HttpOnly` + `SameSite=Lax` (+ `Secure` en production).
- Validation des entrées (zod), taille de corps limitée à 64 Ko.
- RGPD : l'adresse IP est conservée à des fins de modération uniquement (mention dans
  les pages).

[Unreleased]: https://github.com/lucaslapl/Observatoire-des-ABC/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/lucaslapl/Observatoire-des-ABC/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lucaslapl/Observatoire-des-ABC/releases/tag/v0.1.0

### Ajouté
- Référencement des Atlas de la Biodiversité Communale (ABC) à partir de 4 sources officielles :
  registre OFB (data.gouv, maj 2026-07-09), archives Wayback 2022-12-06, Fonds vert P113 2024 et 2025.
- Pipeline CLI : `collect` (ingestion + statuts + géocodage + anomalies), `status`, `geocode`,
  `export:csv` / `export:geojson`, `verify` (worklist de vérification).
- Modèle de statuts agrégés (`en_cours`, `a_venir`, `termine`, `inconnu`) sans destruction
  de la donnée officielle.
- Règles de cohérence temporelle (durée d'un ABC ≈ 3 ans) :
  - « en cours » commencé ≥ 3 ans → **potentiellement terminé** (flag `potentiellement_termine`) ;
  - « va débuter » annoncé ≥ 2 ans → **potentiellement en cours** (flag `potentiellement_en_cours`) ;
  - statut inconnu de plus de 5 ans → reclassé **Terminé (estimation)** (flag `estime_termine`) ;
  - projets connus uniquement via les archives 2022 → signalés « à vérifier ».
- Détection d'anomalies de rattachement : centroïde **médian** par projet, seuil de
  **100 km** ; les communes incohérentes sont écartées des connexions, marquées en rouge
  et listées en vérification.
- Mini-app web Leaflet : carte interactive (filtres statut/région/commune), popups détaillées
  (notes, sources, verdicts), légende avec métadonnées, **connexions en étoile** entre les
  communes d'un même ABC, bouton « Recentrer » avec repli France métropolitaine.
- Page de vérification manuelle `/verify` : verdicts persistants (confirmé terminé/en cours,
  toujours à venir, introuvable, incertain), notes et liens sources, compteurs et filtres.
  Les vérifications survivent à `npm run collect`.
- Géocodage via `geo.api.gouv.fr` (cache local, 12 workers concurrents).

### Corrigé
- L'instantané Wayback (2022) écrasait les statuts plus récents du registre (2026) :
  le collecteur Wayback est désormais non destructif (snapshots + projets absents du registre uniquement).
- CSV source avec guillemets mal échappés → lecture tolérante (`relax_quotes`, `relax_column_count`).
- Ordre lat/lon inversé côté Leaflet (GeoJSON `[lon, lat]` vs `[lat, lon]`).
- Clés étrangères bloquant `collect` (`node:sqlite` les active par défaut) →
  `PRAGMA foreign_keys = OFF` pendant la purge, vérifications conservées.
- Centroïde **moyen** trompé par les coordonnées erronées (cas Martinique) → centroïde **médian**.
- Recalcul incrémental des flags : un flag ne pouvait pas repasser de 1 à 0.
- Cache navigateur (données périmées) → en-têtes `Cache-Control: no-store` sur le serveur.
- Codes INSEE erronés dans les sources :
  - Neuillac (17) → **Neulliac** (`56146`, Morbihan) — Pontivy Communauté ;
  - La Celette (18) → **Cellettes** (`41031`, Loir-et-Cher) — Agglopolys.
  (Codes vérifiés via `geo.api.gouv.fr` : `56124` = Malestroit, `41025` = Bracieux.)
- Bouton « Recentrer » : repli sur la France métropolitaine lorsque l'étendue visible
  inclut l'outre-mer (le `fitBounds` englobait quasiment le globe).

### Sécurité
- Aucun secret dans le dépôt (URLs sources publiques, user-agent explicite uniquement).

[Unreleased]: https://github.com/lucaslapl/Observatoire-des-ABC/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lucaslapl/Observatoire-des-ABC/releases/tag/v0.1.0
