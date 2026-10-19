# Changelog

Toutes les évolutions notables de ce projet sont documentées dans ce fichier.
Le format s'inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

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
- Licence du projet : passage de MIT à « **Tous droits réservés** » (propriétaire).
  Aucune réutilisation autorisée tant que le projet n'est pas viable et autonome
  (`LICENSE`, `package.json`, `README`).

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

[Unreleased]: https://github.com/lucas_lapl/Observatoire-des-ABC/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/lucas_lapl/Observatoire-des-ABC/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/lucas_lapl/Observatoire-des-ABC/releases/tag/v0.1.0

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

[Unreleased]: https://github.com/lucas_lapl/Observatoire-des-ABC/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/lucas_lapl/Observatoire-des-ABC/releases/tag/v0.1.0
