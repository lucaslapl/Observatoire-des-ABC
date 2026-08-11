# Observatoire des ABC — Atlas de la Biodiversité Communale

Recensement des **Atlas de la Biodiversité Communale (ABC)** de France métropolitaine et d'outre-mer, à partir de sources officielles, présenté sur une carte interactive (Leaflet) avec suivi des vérifications manuelles.

> Le site officiel `abc.naturefrance.fr` étant hors ligne, les données proviennent de registres et archives fiables (voir [Sources](#sources-de-données)).

---

## Commandes

| Commande | Rôle |
|---|---|
| `npm run collect` | Télécharge les sources, reconstruit la base, géocode, calcule statuts + anomalies |
| `npm run status` | Recalcule les statuts/flags et les anomalies sur la base existante |
| `npm run export:csv` | Exporte `data/exports/abc-projets.csv` (1 ligne/projet, colonne `note`) |
| `npm run export:geojson` | Exporte `data/exports/abc.geojson` (1 point/commune) |
| `npm run verify` | Génère `data/exports/verification-worklist.csv` (projets à vérifier + requêtes) |
| `npm run serve` | Lance la mini-app web sur `http://localhost:4000` (variable `PORT` pour changer) |
| `npm run start:server` / `stop:server` | **Aide dev Windows uniquement** (démarre/arrête via netstat.exe + taskkill) |
| `npm run typecheck` | `npx tsc --noEmit` |

## Prérequis & installation

- **Node.js ≥ 24** (le projet utilise le module natif expérimental `node:sqlite`, stable à partir de Node 24).
- `npm ci` puis `npm run collect` pour construire la base (le géocodage initial interroge `geo.api.gouv.fr` sur ~5 000 communes, quelques minutes).
- `npm run serve` → http://localhost:4000.

Fonctionnement sous Windows (développement) : Node est appelé via `node.exe` (symlinks dans `~/bin`), la base est `data/abc.db` (SQLite natif `node:sqlite`).

## Déploiement

Serveur Linux (ou tout hébergeur Node) :

```bash
git clone <votre-depot> observatoire-des-abc && cd observatoire-des-abc
npm ci
npm run collect      # premier remplissage (réseau requis)
PORT=8080 npm run serve
```

- La variable d'environnement `PORT` est lue par le serveur (défaut : 4000).
- Mettre en place un **reverse proxy** (nginx ou Caddy) vers le port Node pour exposer
  le domaine et terminer le **HTTPS**. Les réponses du serveur sont déjà servies avec
  `Cache-Control: no-store` (aucun risque de données périmées).
- `data/` est régénéré à la main via `npm run collect` (pas de tâche planifiée fournie —
  prévoir un cron si vous voulez rafraîchir les sources périodiquement).
- Les scripts `start:server` / `stop:server` sont spécifiques à Windows (dev) : sous Linux,
  utilisez `npm run serve` et votre propre gestionnaire de processus (systemd, PM2…).

---

## Sources de données

| Source | Contenu | Fraîcheur |
|---|---|---|
| **Registre OFB** (data.gouv.fr) | ~1 112 projets / 5 056 communes (référence « vivante ») | mise à jour ~annuelle |
| **Archives Wayback** (2022-12-06) | 846 projets, historique ; sert de snapshots + apporte les projets disparus du registre | figé à 2022 |
| **Fonds vert P113 biodiversité 2024** | 75 projets ABC | publié 2025-07-31 |
| **Fonds vert P113 biodiversité 2025** | 65 projets ABC (AMI OFB, classés « va débuter ») | publié 2026-06-22 |

Clé de jointure des projets : slug de `nom | structure_porteuse | annee_debut`.

---

## Statuts & règles de cohérence

Statuts agrégés : `en_cours`, `a_venir`, `termine`, `inconnu`.

Le statut **officiel** n'est jamais détruit : les corrections sont des **flags/notes** à part, sauf cas explicites (estimation) qui restent tracés.

| Règle | Déclencheur | Effet |
|---|---|---|
| Historique « Fini » | un snapshot Wayback mentionne `Fini` | statut → `termine` |
| **Potentiellement terminé** | `en_cours` + `annee_debut ≤ année − 3` (durée ≈ 3 ans) | flag `potentiellement_termine` |
| **Potentiellement en cours** | `a_venir` + `annee_debut ≤ année − 2` | flag `potentiellement_en_cours` |
| **Terminé (estimation)** | statut `inconnu` + `annee_debut > 5 ans` | statut → `termine` + flag `estime_termine` |
| Fonds vert 2025 | source `fondsvert-p113-2025` | statut → `a_venir` |
| **Archives 2022** | projet connu uniquement via Wayback | flag « statut issu des archives 2022, à vérifier » |

Les seuils utilisent `annee_debut ≥ 2000` (garde anti-valeurs aberrantes, ex. `annee_debut = 1`).

⚠️ **Bug corrigé** : l'instantané Wayback (2022) écrasait les statuts plus récents du registre (2026). Désormais il n'ajoute que les snapshots et les projets absents du registre (86 restants), sans jamais surcharger la donnée fraîche.

---

## Anomalies de communes (rattachements incohérents)

Détection automatique lors du géocodage :

- **Centroïde médian** du projet (robuste aux outliers — la moyenne était tirée par les mauvaises coordonnées et flaguait tout le groupe, ex. Martinique).
- Une commune est **incohérente** si elle est à **> 100 km** du centroïde de son groupe.
- Les communes incohérentes sont **écartées des connexions visuelles**, marquées d'un liseré rouge, avec la distance dans la popup, et ajoutées à la page `/verify` (motif « Commune incohérente »).
- Les grands territoires légitimes (PNR Lorraine ~116 km, Landes ~95 km…) restent < 100 km du centre et ne sont pas touchés.

---

## Vérification manuelle — page `/verify`

Accessible via le bouton « 🛠 Vérifier » de la carte. Liste les ~390 projets à vérifier (potentiellement terminé / potentiellement en cours / archives 2022 / commune incohérente) avec une **requête pré-construite** (DuckDuckGo) ciblée. Pour chaque projet :

- verdict : `✓ Confirmé terminé` / `Confirmé en cours` / `Toujours à venir` / `Introuvable` / `Incertain`
- champ note + champ lien source
- sauvegarde en base (`table verifications`) — **survit aux `npm run collect`** (slugs stables)

Un verdict concluant **remplace les alertes spéculatives** sur la carte (couleur du point + badge « ✓ Vérifié », légende « N vérifiés manuellement »).

---

## Connexions inter-communes

Case « Connexions » dans les filtres : les communes d'un même ABC sont reliées à un **point central (centroïde)** par des **traits pleins** (topologie en étoile, couleur = statut du projet). Les communes incohérentes ne sont pas reliées. ~252 projets multi-communes concernés.

---

## Incohérences existantes (à vérifier / corriger)

| Commune | Projet | Problème | Suivi |
|---|---|---|---|
| **Saint-Pierre** (code `97416`) | ABC du Nord de la Martinique | Le registre source donne le code INSEE de Saint-Pierre **de La Réunion** au lieu de Martinique (`97225`) → géocodée à ~13 319 km | à corriger/vérifier |
| **Coutras** (Gironde) | ABC de la Vallée de la Dordogne (Epidor) | Rattachement non confirmé (aucune source trouvée) ; 136 km du groupe | à vérifier |

Ces communes restent écartées des connexions et visibles dans `/verify` (motif « Commune incohérente »).

---

## Incohérences corrigées

| Code erroné | Commune (source) | Correction | Projet | Corrigé le |
|---|---|---|---|---|
| `17258` | Neuillac (Charente-Maritime) | **Neulliac** (`56146`, Morbihan) — faute d'orthographe | ABC de Pontivy Communauté | 2026-08-11 |
| `18041` | La Celette (Cher) | **Cellettes** (`41031`, Loir-et-Cher) — faute d'orthographe | ABC Agglopolys (Blois) | 2026-08-11 |

> ⚠️ Codes vérifiés via `geo.api.gouv.fr` : `56124` est en réalité **Malestroit** et `41025` **Bracieux** (première version erronée, corrigée le jour même).

Les corrections sont dans **`src/corrections.ts`** (clé : code INSEE erroné), appliquées à l'ingestion des 3 sources (datagouv, wayback, fondsvert) : elles rétablissent code, nom, département, région et coordonnées, et **survivent à `npm run collect`**. Pour corriger une nouvelle erreur, ajouter une entrée dans ce fichier.

---

## Journal des décisions & bugs corrigés

- **Wayback écrasait les statuts récents** (2022 > 2026) → collecteur rendu non destructif.
- **CSV source avec guillemets cassés** (`"PETR '"Pays de la Jeune Loire'"`) → `relax_quotes` + `relax_column_count`.
- **Lat/lon inversés** dans Leaflet (GeoJSON `[lon, lat]`, Leaflet attend `[lat, lon]`).
- **FK bloquait `collect`** : `node:sqlite` active les clés étrangères par défaut → `PRAGMA foreign_keys = OFF` pendant la purge, les vérifications persistent.
- **Centroïde moyen trompé par les outliers** (Martinique) → centroïde médian.
- **Mise à jour incrémentale des flags** : ne se déclenchait que 0→1, jamais 1→0 → comparaison avec la valeur stockée.
- **Cache navigateur** : en-têtes `Cache-Control: no-store` sur toutes les réponses serveur.
- Géocodage **`geo.api.gouv.fr/communes/<code>`** (12 workers concurrents, cache `data/cache/geo.json`).

---

## Limites connues

- Les sources sont **déclaratives** et mises à jour avec retard : d'où les règles de cohérence temporelle (durée d'un ABC ≈ 3 ans).
- Certains ABC ont des communes homonymes : la correction se fait au cas par cas via `src/corrections.ts`.
- La fraîcheur des statuts dépend du dernier `npm run collect`.
- La page `/verify` stocke les verdicts dans la base locale : ils ne sont pas partagés entre plusieurs déploiements.

## Attributions & licences des données

- **Données ABC** : registre OFB et lauréats Fonds vert publiés sur **data.gouv.fr**
  sous [licence Ouverte / Open Licence 2.0 (Etalab)](https://www.etalab.gouv.fr/licence-ouverte-open-licence/).
- **Géocodage** : [geo.api.gouv.fr](https://geo.api.gouv.fr/) (données ouvertes).
- **Carte** : [Leaflet](https://leafletjs.com/) (BSD-2-Clause), tuiles © [OpenStreetMap](https://www.openstreetmap.org/copyright) contributors (ODbL).
- Code du projet : MIT — voir [LICENSE](LICENSE).

## Licence

Voir [LICENSE](LICENSE) (MIT).
