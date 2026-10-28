# API publique v1 — guide de section

API publique **en lecture seule**, versionnée (`/api/v1`, jamais de breaking
change dans une version publiée), accès ouvert **sans clé**, données sous
**Licence Ouverte 2.0 (Etalab)**. Objectif : permettre aux sites tiers et aux
collectivités de réutiliser les données (sources agrégées + corrections et
ajouts des contributeurs) en créditant la source.

La documentation interactive est servie sur **`/api/doc`** (HTML Scribe) et la
spécification OpenAPI sur **`/api/doc/openapi.yaml`**. La page publique
« API & conditions d'utilisation » vit sur **`/api`** (crédit prêt à copier,
limites d'usage, versionnement).

## Endpoints

| Endpoint | Description |
| --- | --- |
| `GET /api/v1/projets` | Liste paginée par curseur. Filtres : `statut` (a_venir, en_cours, termine, inconnu — statut **affiché**, le verdict de vérification prime), `annee` (année de début), `code_insee`, `departement` (ex. 56, 2a, 972), `region` (slug, code INSEE ou libellé), `porteur` (recherche partielle), `bbox` (`lon_min,lat_min,lon_max,lat_max`, WGS84). |
| `GET /api/v1/projets/{id}` | Fiche détaillée : communes, vérification, enrichissements, historique (anonymisé), provenance. |
| `GET /api/v1/communes/{code_insee}` | Fiche commune + projets liés (avec provenance de chaque projet). |
| `GET /api/v1/departements/{code}` | Synthèse d'un département. |
| `GET /api/v1/regions/{code}` | Synthèse d'une région (slug, code INSEE ou libellé). |
| `GET /api/v1/journal` | Journal public d'audit (mêmes entrées et même ordre que l'historique des fiches), contributions créditées collectivement. |
| `GET /api/v1/stats` | Compteurs globaux (mêmes chiffres que `/api/meta`). |
| `GET /api/v1/communes/{code_insee}/patrimoine` | Patrimoine écologique INPN de la commune : ZNIEFF, sites Natura 2000 et aires protégées (faits bruts, espèces confidentielles jamais diffusées). |
| `GET /api/v1/patrimoine/znieff` | Liste paginée des ZNIEFF touchant au moins une commune ABC (100/page). |
| `GET /api/v1/patrimoine/znieff/{id_national}` | Fiche ZNIEFF : intérêt, communes ABC, espèces déterminantes (hors confidentielles), parent/enfants. |
| `GET /api/v1/patrimoine/natura` | Liste paginée des sites Natura 2000 touchant au moins une commune ABC (100/page). |
| `GET /api/v1/patrimoine/natura/{sitecode}` | Fiche Natura 2000 : habitats, espèces de la directive, DOCOB, communes ABC. |

**Patrimoine INPN — attribution spéciale** : ces endpoints exposent des
faits bruts de l'INPN, **pas couverts par la Licence Ouverte** de
l'Observatoire. Chaque réponse porte son bloc `attribution` (MNHN,
licences de l'INPN, mention de crédit) et la `versions` de référence de
chaque base ; les réutilisateurs doivent créditer l'INPN/MNHN comme sur
le site. Même périmètre éditorial que le site : uniquement les zonages
touchant au moins une commune ABC.

Formats : **JSON** par défaut ; **GeoJSON** pour `/projets` et `/communes`
via `?format=geojson` ou `Accept: application/geo+json` (négociation). La
logique de statut affiché est celle de l'export GeoJSON existant
(`GeoJsonService` / `StatusService`).

Chaque enregistrement expose ses métadonnées de provenance :
`source_origine`, `licence` ("Etalab-2.0"), `derniere_mise_a_jour` (ISO 8601),
`enrichi_par_contributeurs` (nombre de corrections humaines validées),
`statut_verification` (`verifie` / `en_attente`, reflétant la file de
vérification).

## Pagination

**Par curseur uniquement** (jamais d'offset) : `?per_page=25` (plafond 100),
puis suivre `meta.next_cursor` / `meta.next_page_url` (`?cursor=…`).

## Cache, ETag, 304

- Toutes les réponses GET portent `ETag` (SHA-256 du corps) et
  `Cache-Control: public, max-age=3600` ; un `If-None-Match` correspondant
  répond `304` sans corps.
- **Cache applicatif complet** : chaque réponse est mise en cache en entier
  (TTL 1 h, clé = route + query string triée) par
  `App\Http\Middleware\CacheApiResponse` — un hit est servi sans requête SQL
  ni sérialisation.
- Invalidation : `App\Support\ApiCache` (un « epoch » préfixe les clés).
  Toute écriture sur `projets`, `communes`, `verifications`, `contributions`,
  `audit_log` déclenche un `ApiCache::invalidate()` (observateur
  `ApiCacheObserver`) ; les traitements massifs qui contournent les
  événements de modèle invalident explicitement (`CollectService`,
  `StatusRecomputeService`, `GeocodeService`, `abc:import-legacy`). Une
  contribution validée ou un verdict de vérification est donc visible
  immédiatement.
- En production, le cache doit vivre dans un store partagé entre les workers
  (`CACHE_STORE=redis` recommandé ; `database` fonctionne aussi).

## Throttling et CORS

- `throttle:api-v1` : **60 req/min par IP** (limiter déclaré dans
  `AppServiceProvider`, valeur dans `config/api.php` ; HTTP 429 + `Retry-After`
  au-delà). Clé API pour des quotas supérieurs : prévue plus tard, pas en v1.
- CORS ouvert pour `api/v1/*` seulement (`config/cors.php`) : origines
  tierces autorisées, `ETag`/`Cache-Control` exposés aux navigateurs.
  L'API historique non versionnée (utilisée par la carte du site) n'y gagne
  pas CORS — comportement inchangé.

## Aucune donnée personnelle

L'API n'expose **ni e-mail, ni IP, ni identifiant utilisateur, ni nom** :
`verifie_par`, `user_id`, `ip`, `user_agent`, `traite_par` ne sortent jamais ;
les contributions sont créditées collectivement (« les contributeurs de
l'Observatoire des ABC »). L'audit anti-PII automatisé
(`tests/Feature/ApiV1PiiAuditTest.php`) parcourt toutes les réponses et échoue
s'il trouve la moindre PII.

## Isolation de la charge (Docker)

Le conteneur applicatif fait tourner **nginx + deux pools PHP-FPM**
(`docker/php/pool-www.conf`, `docker/php/pool-api.conf`) : les requêtes
`/api/v1/*` partent sur le pool `api` (workers statiques, plafonnés à 4,
`request_terminate_timeout`), le site public garde son propre pool dynamique.
Un pic de trafic tiers ne peut donc pas priver le site de ses process.
En production (Plesk/FPM), reproduire le même schéma : pool dédié pour
`/api/v1/*`.

## Documentation OpenAPI (Scribe)

Les docs sont **générées et committées** (`public/docs/`) : la production
sert des fichiers statiques et n'a pas besoin de Scribe.

```bash
# Regénérer pour la production (URL de base des exemples = le site public,
# collectivités et réutilisateurs copient-collent des requêtes qui marchent) :
docker compose exec -e SCRIBE_BASE_URL=https://observatoire-abc.fr \
    -e APP_URL=https://observatoire-abc.fr \
    app php artisan scribe:generate

# Regénérer pour le développement local (URL http://localhost:8000) :
docker compose exec app php artisan scribe:generate

# La doc est alors visible sur http://localhost:8000/api/doc
# et la spécification sur http://localhost:8000/api/doc/openapi.yaml
```

`SCRIBE_BASE_URL` prime sur `APP_URL` pour l'URL affichée (`scribe.base_url`) ;
`APP_URL` doit aussi être surchargée à la génération : les réponses-exemples
sont capturées depuis l'application, et les `next_page_url` qu'elles
contiennent sont construits avec l'URL de la requête. Sans cette double
surcharge, des exemples pointeraient encore vers localhost.

Scribe est une dépendance de développement (`knuckleswtf/scribe` en
`require-dev`), configuré dans `config/scribe.php` (type `static`, sortie
`public/docs`, description/licence/crédit, uniquement les routes `api/v1`).

## Fichier de configuration

`config/api.php` : licence, mention de crédit, TTL du cache, tailles de
pagination, limite de throttling, libellés `source_origine`.

## Tests

```bash
docker compose exec app php artisan test --filter="ApiV1|ApiPage"
```

Couverture : chaque endpoint (200, filtres, pagination par curseur, GeoJSON,
provenance, 404, 422 en français), cache applicatif (deux GET identiques =
une seule exécution SQL, ETag/304, invalidation sur vérification), audit
anti-PII, throttling (61e requête = 429), CORS, page publique `/api` + doc.
