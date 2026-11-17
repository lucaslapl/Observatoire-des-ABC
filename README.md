# Observatoire des ABC

[![CI](https://github.com/lucaslapl/Observatoire-des-ABC/actions/workflows/ci.yml/badge.svg)](https://github.com/lucaslapl/Observatoire-des-ABC/actions/workflows/ci.yml)
![PHP](https://img.shields.io/badge/PHP-%E2%89%A58.3-777BB4?logo=php&logoColor=white)
![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?logo=laravel&logoColor=white)
![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)
![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16%2BPostGIS-4169E1?logo=postgresql&logoColor=white)
![Licence](https://img.shields.io/badge/licence-AGPL--3.0-blue)

Observatoire national des Atlas de la Biodiversité Communale (ABC) : suivi des
projets ABC financés, vérifications manuelles, contributions du public et
export cartographique. Site public : <https://observatoire-abc.fr/>

## Présentation

L'Observatoire des ABC recense et met à jour l'ensemble des projets Atlas de
la Biodiversité Communale portés par les collectivités françaises. Il agrège
plusieurs sources publiques (registre national des ABC sur data.gouv.fr, lots
biodiversité du Fonds vert 2024 et 2025, archives 2022 via la Wayback Machine),
en dérive des fiches riches — statuts, porteurs, années, communes — et publie
le tout sur une carte interactive où chaque projet est vérifiable, corrigeable
et commentable par les humains. Il met également en regard de chaque commune
l'inventaire national du patrimoine naturel (INPN) : ZNIEFF, sites Natura
2000 et aires protégées qui touchent son territoire.

La philosophie du projet tient en un principe : **la machine collecte, mais ne
touche jamais le travail humain**. Les collectes automatiques synchronisent les
données des sources en ligne ; les vérifications, les contributions et le
journal d'audit ne sont jamais purgés ni écrasés. Un projet disparu des
sources mais porteur d'un travail humain est conservé ; un projet supprimé
par un administrateur n'est pas réimporté tant que l'exclusion n'est pas
levée explicitement.

Le jeu de données de référence compte environ **1260 projets** et
**5400 communes** couvertes.

## Fonctionnalités

- **Carte interactive** de tous les projets ABC : marqueurs simples,
  intercommunaux et agrégés, communes reliées par des traits au clic.
- **Pages publiques** : fiche d'un projet, pages commune, département et
  région, fil d'actualités, page méthodologie (comment les données sont
  collectées, vérifiées et corrigées), mentions légales et politique de
  confidentialité.
- **Patrimoine écologique (INPN)** : chaque page commune affiche les
  zonages des inventaires nationaux du patrimoine naturel qui touchent
  son territoire — ZNIEFF (avec espèces déterminantes), sites Natura
  2000 (habitats de l'annexe I, espèces des directives « Habitats » et
  « Oiseaux », documents d'objectifs) et aires protégées (avec les
  surfaces recouvrant la commune). Des fiches dédiées sont publiées
  pour les zonages touchant au moins une commune ABC (5 420 ZNIEFF,
  749 sites Natura 2000), reliées entre elles, indexées dans le
  sitemap et datées de la version de la base INPN importée. Des faits
  bruts, jamais d'indicateur composite ; les espèces confidentielles
  ne sont jamais exposées.
- **Signalement de correction** (public) : depuis la carte, chaque visiteur
  peut proposer une correction ; la contribution est modérée avant
  publication.
- **Espace membre** : inscription ouverte avec vérification d'email, page
  profil (contributions, mot de passe, suppression du compte). Les
  contributions d'un compte supprimé sont conservées et anonymisées — le
  travail humain n'est jamais perdu.
- **File de vérification** : confirmation ou correction des projets un par
  un, incluant les doublons à départager et les financements non rattachés.
- **Exports** : CSV, GeoJSON et export du journal d'audit.
- **RGPD** : mesure d'audience anonyme, chargée par défaut et révocable à
  tout moment (bannière dédiée).

## Sources de données

| Source | Contenu |
| --- | --- |
| data.gouv.fr (registre OFB) | Projets ABC financés |
| Fonds vert — lot biodiversité (P113) | Financements accordés à des ABC : une ligne Fonds vert ne crée jamais de projet, elle est rattachée à un projet existant ou signalée pour vérification |
| Wayback Machine | Archives 2022 du registre, pour l'historique des projets |
| geo.api.gouv.fr | Géocodage des communes manquantes |
| INPN (MNHN) | Référentiels du patrimoine naturel : inventaire ZNIEFF, base Natura 2000 (formulaires standards de données, documents d'objectifs) et inventaire des aires protégées |
| IGN — Admin Express (COG) | Découpage administratif des communes, pour le recouvrement aires protégées ↔ communes (calcul à l'import, ordres de grandeur à l'échelle communale) |

Les données restent sous la licence de leurs producteurs (data.gouv.fr,
INPN, IGN — Licence Ouverte). Les référentiels INPN sont importés
localement via `abc:import-inpn` puis exportés en SQL portable
(`abc:export-inpn`) vers l'hébergement de production, qui n'a pas
PostGIS : aucune géométrie n'est stockée en base, les croisements se
font par code INSEE de commune.

## Déploiement et maintenance

Le site public tourne sur un hébergement mutualisé (MySQL, pas de PostGIS) ;
la liste précise des étapes de mise à jour de production est reportée dans
le message de commit de chaque push.

### `.env` de production — schéma https (audit SEO du 2026-11-17, C6)

Derrière le frontal TLS de l'hébergeur (nginx/Plesk), la requête arrive en
`http://` au niveau de PHP-FPM : sans configuration, tout ce qui dérive de
`url('/')` — sitemap, canonicals, flux Atom, doc Scribe — est généré en
`http://` (constat : 13 531 `<loc>` de sitemap en `http://`, zéro en
`https://`). Deux variables à poser dans le `.env` de production :

```dotenv
APP_URL=https://observatoire-abc.fr
TRUSTED_PROXIES=*
```

- `APP_URL` fixe la racine des URLs en contexte console (artisan, cron,
  `abc:sitemap:flush`) et la doc Scribe ;
- `TRUSTED_PROXIES` (IPs/CIDRs du frontal, séparés par des virgules, ou
  `*` quand l'hébergeur ne publie pas l'IP du frontal — compromis assumé)
  fait confiance au `X-Forwarded-Proto` : `request()->getScheme()` vaut
  `https` et `url('/')` rend `https://` même sans `APP_URL`.

Après modification : `php artisan config:cache` si la config est cachée,
puis `php artisan abc:sitemap:flush` (voir ci-dessous) et vérification
`curl -s https://observatoire-abc.fr/sitemap.xml | head` — chaque `<loc>`
doit commencer par `https://`.

### Sitemap

Après chaque déploiement, la purge du sitemap est à jouer
systématiquement :

```bash
php artisan abc:sitemap:flush
```

La commande oublie toutes les clés de cache `abc:sitemap*` (y compris les
entrées héritées d'anciennes versions du générateur, de toute façon ignorées
depuis le passage à la clé versionnée `abc:sitemap:v2`), régénère le sitemap
complet et affiche le nombre total d'URLs et la répartition par section —
pages statiques, actualités, fiches ABC, communes, départements, régions,
patrimoine INPN (ZNIEFF, Natura 2000, pages de périmètre des fiches ABC).
Sur un hébergement sans accès shell, le même traitement est disponible dans
le panneau d'administration (Maintenance → « Régénérer le sitemap », route
admin protégée `/api/admin/sitemap/flush`).

## Licence

**AGPL-3.0** (GNU Affero General Public License v3) — voir `LICENSE`.

Le code de l'Observatoire est libre : vous pouvez l'utiliser, l'étudier, le
modifier et le redistribuer, à condition de publier les sources des versions
modifiées mises à disposition en ligne (section 13, « Remote Network
Interaction »).
