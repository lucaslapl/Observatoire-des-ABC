# Observatoire des ABC

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
et commentable par les humains.

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

Les données restent sous la licence de leurs producteurs (data.gouv.fr,
Licence Ouverte).

## Licence

**AGPL-3.0** (GNU Affero General Public License v3) — voir `LICENSE`.

Le code de l'Observatoire est libre : vous pouvez l'utiliser, l'étudier, le
modifier et le redistribuer, à condition de publier les sources des versions
modifiées mises à disposition en ligne (section 13, « Remote Network
Interaction »).
