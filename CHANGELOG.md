# Changelog

Toutes les évolutions notables de ce projet sont documentées dans ce fichier.
Le format s'inspire de [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

## [Unreleased]

### Changé
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
