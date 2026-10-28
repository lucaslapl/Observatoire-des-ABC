<?php

namespace App\Services;

use App\Models\Commune;
use App\Models\Contribution;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * API publique v1 (lecture seule) : mêmes données que le site (sources
 * agrégées + travail humain : vérifications, contributions validées),
 * enrichies de métadonnées de provenance (licence Etalab 2.0).
 *
 * Principes :
 * - aucune donnée personnelle (ni e-mail, ni IP, ni identifiant
 *   utilisateur, ni nom) : le travail humain est crédité collectivement ;
 * - le statut vérifié prime sur le statut calculé, comme sur le site ;
 * - les réponses sont construites une fois puis servies par le cache
 *   applicatif (CacheApiResponse).
 */
class ApiV1Service
{
    public function __construct(
        private LandingService $landing,
        private ChangelogService $changelog,
        private StatusService $status,
        private RegionService $regions,
        private StatsService $stats,
        private PatrimoineEcologiqueService $patrimoine,
        private PatrimoineFicheService $patrimoineFiches,
    ) {}

    // -----------------------------------------------------------------
    // Projets (liste paginée par curseur, filtrable)
    // -----------------------------------------------------------------

    /**
     * Liste des projets, filtrée puis paginée par curseur.
     *
     * @param  array{statut?: string, annee?: int, code_insee?: string, departement?: string, region?: string, porteur?: string, bbox?: array{0: float, 1: float, 2: float, 3: float}}  $filters
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function projets(array $filters, int $perPage): array
    {
        /** @var CursorPaginator<Projet> $page */
        $page = $this->projetsQuery($filters)->cursorPaginate($perPage);

        return [
            'data' => $this->mapProjets($page),
            'meta' => $this->cursorMeta($page),
        ];
    }

    /**
     * Collection GeoJSON de la liste des projets (une feature par projet,
     * point de la commune principale — même logique de statut que
     * l'export GeoJSON existant).
     *
     * @param  array{statut?: string, annee?: int, code_insee?: string, departement?: string, region?: string, porteur?: string, bbox?: array{0: float, 1: float, 2: float, 3: float}}  $filters
     * @return array<string, mixed>
     */
    public function projetsGeoJson(array $filters, int $perPage): array
    {
        $projets = $this->projets($filters, $perPage);

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(function (array $item): array {
                $coord = $item['localisation'];

                return [
                    'type' => 'Feature',
                    'geometry' => $coord === null ? null : [
                        'type' => 'Point',
                        'coordinates' => [$coord['lon'], $coord['lat']],
                    ],
                    'properties' => $this->sansLocalisation($item),
                ];
            }, $projets['data']),
            'meta' => $projets['meta'],
        ];
    }

    /**
     * Fiche détaillée d'un projet (404 JSON si inconnu).
     *
     * @return array<string, mixed>
     */
    public function projet(string $id): array
    {
        $projet = Projet::query()->find($id);

        if ($projet === null) {
            throw new HttpResponseException(response()->json(['message' => 'Projet introuvable.'], 404));
        }

        // Même fiche que la page publique, historique anonymisé (crédit
        // collectif des contributeurs, jamais nominatif).
        $fiche = $this->landing->projet($projet);
        $fiche['historique'] = $this->changelog->pourProjet($projet->id, anonymiser: true);
        $fiche['statut_verification'] = $this->statutVerification($projet->verification?->etat);

        $enrichi = Contribution::query()
            ->where('projet_id', $projet->id)
            ->where('statut', 'validee')
            ->count();

        $fiche['provenance'] = $this->provenance($projet, $projet->verification, $enrichi);

        return $fiche;
    }

    // -----------------------------------------------------------------
    // Communes / départements / régions
    // -----------------------------------------------------------------

    /**
     * Fiche d'une commune (404 si inconnue) : mêmes données que la page
     * publique, projets liés enrichis de leur provenance.
     *
     * @return array<string, mixed>
     */
    public function commune(string $code): array
    {
        return $this->avecProvenance($this->landing->commune($code));
    }

    /**
     * Collection GeoJSON d'une commune : une feature par projet lié,
     * positionnée sur le point de la commune.
     *
     * @return array<string, mixed>
     */
    public function communeGeoJson(string $code): array
    {
        $fiche = $this->commune($code);

        $point = Commune::query()
            ->where('code_geographique', $code)
            ->whereNotNull('lon')->whereNotNull('lat')
            ->where(fn ($q) => $q->where('lon', '!=', 0)->orWhere('lat', '!=', 0))
            ->first(['lon', 'lat']);

        $geometry = $point !== null
            ? ['type' => 'Point', 'coordinates' => [(float) $point->lon, (float) $point->lat]]
            : null;

        return [
            'type' => 'FeatureCollection',
            'features' => array_map(fn (array $projet): array => [
                'type' => 'Feature',
                'geometry' => $geometry,
                'properties' => $projet,
            ], $fiche['projets']),
            'commune' => collect($fiche)->except('projets')->all(),
        ];
    }

    /**
     * Synthèse d'un département (404 si inconnu).
     *
     * @return array<string, mixed>
     */
    public function departement(string $code): array
    {
        return $this->avecProvenance($this->landing->departement($code));
    }

    /**
     * Synthèse d'une région (404 JSON si inconnue), par slug ou code INSEE.
     *
     * @return array<string, mixed>
     */
    public function region(string $code): array
    {
        $label = $this->regions->resolveBySlug(mb_strtolower($code)) ?? $this->regions->label($code);

        if ($label === null) {
            throw new HttpResponseException(response()->json(['message' => 'Région introuvable.'], 404));
        }

        $fiche = $this->landing->region($this->regions->slug($label));
        $fiche['licence'] = $this->licence();
        $fiche['derniere_mise_a_jour'] = $this->derniereMajRegion($label);

        return $fiche;
    }

    // -----------------------------------------------------------------
    // Patrimoine écologique INPN (ZNIEFF, Natura 2000, aires protégées)
    // -----------------------------------------------------------------

    /**
     * Patrimoine écologique d'une commune : ZNIEFF, sites Natura 2000
     * et aires protégées la touchant (faits bruts INPN, espèces
     * confidentielles jamais diffusées), avec attribution MNHN.
     * 404 JSON si la commune n'existe pas dans le référentiel (communes
     * sans ABC comprises, comme la page publique).
     *
     * @return array<string, mixed>
     */
    public function patrimoineCommune(string $code): array
    {
        $existe = Commune::query()->where('code_geographique', $code)->exists();

        if (! $existe) {
            throw $this->introuvable('Commune introuvable.');
        }

        return $this->avecAttributionInpn($this->patrimoine->pourCommune($code));
    }

    /**
     * Liste paginée des ZNIEFF éligibles (touchant au moins une commune
     * ayant un ABC), même périmètre éditorial que le site.
     *
     * @return array<string, mixed>
     */
    public function patrimoineZnieffListing(int $page): array
    {
        return $this->avecAttributionInpn($this->patrimoineFiches->znieffListing($page));
    }

    /**
     * Fiche ZNIEFF (404 JSON si inconnue ou hors périmètre ABC).
     *
     * @return array<string, mixed>
     */
    public function patrimoineZnieff(string $idNational): array
    {
        try {
            return $this->avecAttributionInpn($this->patrimoineFiches->znieff($idNational));
        } catch (NotFoundHttpException) {
            throw $this->introuvable('ZNIEFF introuvable ou hors du périmètre des communes ABC.');
        }
    }

    /**
     * Liste paginée des sites Natura 2000 éligibles.
     *
     * @return array<string, mixed>
     */
    public function patrimoineNaturaListing(int $page): array
    {
        return $this->avecAttributionInpn($this->patrimoineFiches->naturaListing($page));
    }

    /**
     * Fiche Natura 2000 (404 JSON si inconnue ou hors périmètre ABC).
     *
     * @return array<string, mixed>
     */
    public function patrimoineNatura(string $sitecode): array
    {
        try {
            return $this->avecAttributionInpn($this->patrimoineFiches->natura($sitecode));
        } catch (NotFoundHttpException) {
            throw $this->introuvable('Site Natura 2000 introuvable ou hors du périmètre des communes ABC.');
        }
    }

    // -----------------------------------------------------------------
    // Journal public d'audit (contributions créditées collectivement)
    // -----------------------------------------------------------------

    /**
     * Journal public intégral (les mêmes entrées que l'historique des
     * fiches), le plus récent d'abord, paginé par curseur.
     *
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function journal(int $perPage): array
    {
        $journal = $this->changelog->paginateApi($perPage);

        return [
            'data' => $journal['items'],
            'meta' => array_merge($journal['meta'], ['licence' => $this->licence()]),
        ];
    }

    // -----------------------------------------------------------------
    // Statistiques globales
    // -----------------------------------------------------------------

    /**
     * Compteurs globaux (mêmes chiffres que /api/meta).
     *
     * @return array<string, mixed>
     */
    public function stats(): array
    {
        $meta = $this->stats->meta();
        $meta['licence'] = $this->licence();
        $meta['derniere_mise_a_jour'] = $this->derniereMaj();

        return $meta;
    }

    // -----------------------------------------------------------------
    // Construction de la liste des projets
    // -----------------------------------------------------------------

    /**
     * Requête des projets avec filtres. Le filtre statut porte sur le
     * statut affiché (le verdict de vérification prime, comme sur le
     * site). Les filtres géographiques portent sur les communes liées.
     *
     * @param  array{statut?: string, annee?: int, code_insee?: string, departement?: string, region?: string, porteur?: string, bbox?: array{0: float, 1: float, 2: float, 3: float}}  $filters
     */
    private function projetsQuery(array $filters): Builder
    {
        // Statut affiché : verdict de vérification > statut calculé.
        $statutAffiche = 'CASE v.etat'
            ." WHEN 'confirme_termine' THEN 'termine'"
            ." WHEN 'confirme_en_cours' THEN 'en_cours'"
            ." WHEN 'toujours_a_venir' THEN 'a_venir'"
            .' ELSE projets.statut END';

        return Projet::query()
            ->leftJoin('verifications as v', 'v.projet_id', '=', 'projets.id')
            ->select('projets.*', 'v.etat as verif_etat')
            ->when($filters['statut'] ?? null, fn (Builder $q, string $s) => $q->whereRaw($statutAffiche.' = ?', [$s]))
            ->when($filters['annee'] ?? null, fn (Builder $q, int $a) => $q->where('projets.annee_debut', $a))
            ->when($filters['code_insee'] ?? null, function (Builder $q, string $code) {
                $q->whereExists(fn ($sub) => $sub
                    ->selectRaw(1)->from('communes as c')
                    ->whereColumn('c.projet_id', 'projets.id')
                    ->where('c.code_geographique', $code));
            })
            ->when($filters['departement'] ?? null, function (Builder $q, string $dept) {
                $norm = mb_strtolower($dept);
                $len = strlen($norm);
                $q->whereExists(fn ($sub) => $sub
                    ->selectRaw(1)->from('communes as c')
                    ->whereColumn('c.projet_id', 'projets.id')
                    ->where(fn ($w) => $w
                        ->whereRaw('LOWER(SUBSTR(c.code_geographique, 1, ?)) = ?', [$len, $norm])
                        ->orWhereRaw('LOWER(c.departement) = ?', [$norm])));
            })
            ->when(($filters['region'] ?? null) !== null, function (Builder $q) use ($filters) {
                $values = $this->regionValues($filters['region']);
                if ($values === []) {
                    // Région inconnue des données : aucun résultat, pas tout.
                    $q->whereRaw('1 = 0');

                    return;
                }
                $q->whereExists(fn ($sub) => $sub
                    ->selectRaw(1)->from('communes as c')
                    ->whereColumn('c.projet_id', 'projets.id')
                    ->whereIn('c.region', $values));
            })
            ->when($filters['porteur'] ?? null, fn (Builder $q, string $p) => $q->where('projets.structure_porteuse', 'like', '%'.$p.'%'))
            ->when($filters['bbox'] ?? null, function (Builder $q, array $bbox) {
                [$minLon, $minLat, $maxLon, $maxLat] = $bbox;
                $q->whereExists(fn ($sub) => $sub
                    ->selectRaw(1)->from('communes as c')
                    ->whereColumn('c.projet_id', 'projets.id')
                    ->whereBetween('c.lon', [$minLon, $maxLon])
                    ->whereBetween('c.lat', [$minLat, $maxLat]));
            })
            ->orderBy('projets.id');
    }

    /**
     * Valeurs de la colonne communes.region correspondant à une région
     * (paramètre accepté : slug ou code INSEE). Tableau vide = région
     * inconnue dans les données.
     *
     * @return array<int, string>
     */
    private function regionValues(?string $region): array
    {
        if ($region === null || trim($region) === '') {
            return [];
        }

        $label = $this->regions->resolveBySlug(mb_strtolower($region)) ?? $this->regions->label($region);

        if ($label === null) {
            return [];
        }

        return Commune::query()
            ->whereNotNull('region')
            ->distinct()
            ->pluck('region')
            ->filter(fn ($v) => $this->regions->normalizeToLabel($v) === $label)
            ->values()
            ->all();
    }

    /**
     * @param  CursorPaginator<Projet>  $page
     * @return array<int, array<string, mixed>>
     */
    private function mapProjets(CursorPaginator $page): array
    {
        $ids = $page->getCollection()->pluck('id');

        /** @var Collection<string, Collection<int, Commune>> $communes */
        $communes = Commune::query()
            ->whereIn('projet_id', $ids)
            ->orderBy('libelle_geographique')
            ->get()
            ->groupBy('projet_id');

        $enrichis = $this->enrichissements($ids);
        $verifies = Verification::query()->whereIn('projet_id', $ids)->get()->keyBy('projet_id');

        /** @var Collection<int, Projet> $projetsPage */
        $projetsPage = $page->getCollection();

        return $projetsPage->map(function (Projet $projet) use ($communes, $enrichis, $verifies) {
            $cs = $communes->get($projet->id, collect());
            $point = $cs->first(fn (Commune $c) => $c->lon != 0 && $c->lat != 0);

            $statutAffiche = $this->status->statutDepuisVerification($projet->verif_etat) ?? $projet->statut;
            $verification = $verifies->get($projet->id);
            $enrichi = $enrichis->get($projet->id);

            return [
                'id' => $projet->id,
                'nom' => $projet->nom,
                'slug' => $projet->slug,
                'structure_porteuse' => $projet->structure_porteuse,
                'annee_debut' => $projet->annee_debut,
                'annee_fin' => $projet->annee_fin,
                'statut' => $statutAffiche,
                'statut_label' => $this->status->statutLabel($statutAffiche),
                'statut_verification' => $this->statutVerification($verification?->etat),
                'n_communes' => $cs->count(),
                'commune_principale' => $cs->first()?->libelle_geographique,
                'code_insee' => $cs->first()?->code_geographique,
                'departement' => $cs->first()?->libelle_departement,
                'region' => $cs->first() !== null ? $this->regions->normalizeToLabel($cs->first()->region) : null,
                'localisation' => $point !== null ? ['lon' => (float) $point->lon, 'lat' => (float) $point->lat] : null,
                'provenance' => $this->provenance($projet, $verification, (int) ($enrichi['n'] ?? 0), $enrichi['derniere'] ?? null),
            ];
        })->values()->all();
    }

    /**
     * Comptage des contributions validées par projet (travail humain).
     *
     * @param  Collection<int, string>  $ids
     * @return Collection<string, array{n: int, derniere: string|null}>
     */
    private function enrichissements(Collection $ids): Collection
    {
        return Contribution::query()
            ->whereIn('projet_id', $ids)
            ->where('statut', 'validee')
            ->selectRaw('projet_id, COUNT(*) as n, MAX(updated_at) as derniere')
            ->groupBy('projet_id')
            ->get()
            ->keyBy('projet_id')
            ->map(fn (Contribution $row) => ['n' => (int) $row->n, 'derniere' => $row->derniere]);
    }

    /**
     * @param  CursorPaginator<Projet>  $page
     * @return array<string, mixed>
     */
    private function cursorMeta(CursorPaginator $page): array
    {
        return [
            'per_page' => $page->perPage(),
            'path' => $page->path(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'prev_cursor' => $page->previousCursor()?->encode(),
            'next_page_url' => $page->nextPageUrl(),
            'prev_page_url' => $page->previousPageUrl(),
        ];
    }

    // -----------------------------------------------------------------
    // Provenance (exigée sur chaque enregistrement)
    // -----------------------------------------------------------------

    /**
     * Métadonnées de provenance d'un enregistrement.
     *
     * @param  int  $enrichi  nombre de contributions humaines validées
     */
    private function provenance(Projet $projet, ?Verification $verification, int $enrichi, ?string $enrichiLe = null): array
    {
        $derniere = $this->maxDate(array_filter([
            $projet->updated_at?->toDateTimeString(),
            $verification?->verifie_le?->toDateTimeString(),
            $enrichiLe,
        ]));

        return [
            'source_origine' => config('api.sources_origine')[$projet->source] ?? $projet->source,
            'licence' => $this->licence(),
            'derniere_mise_a_jour' => $derniere !== null ? Carbon::parse($derniere)->toISOString() : null,
            'enrichi_par_contributeurs' => $enrichi,
            'statut_verification' => $this->statutVerification($verification?->etat),
        ];
    }

    private function statutVerification(?string $verifEtat): string
    {
        return $verifEtat !== null && $verifEtat !== 'a_verifier' ? 'verifie' : 'en_attente';
    }

    /**
     * 404 JSON homogène (même contrat que la fiche région).
     */
    private function introuvable(string $message): HttpResponseException
    {
        return new HttpResponseException(response()->json(['message' => $message], 404));
    }

    /**
     * Attribution INPN/MNHN sur chaque réponse patrimoine : les faits
     * bruts des référentiels restent sous les licences de l'INPN (pas
     * Etalab), et la version de référence de chaque base est toujours
     * présente — même rigueur que les fiches publiques du site.
     *
     * @param  array<string, mixed>  $fiche
     * @return array<string, mixed>
     */
    private function avecAttributionInpn(array $fiche): array
    {
        $fiche['versions'] ??= DB::table('inpn_imports')->pluck('version_source', 'source')->all();
        $fiche['attribution'] = [
            'source' => (string) config('api.inpn.source'),
            'url' => (string) config('api.inpn.url'),
            'licences' => (string) config('api.inpn.licences'),
            'credit' => (string) config('api.inpn.credit'),
        ];

        return $fiche;
    }

    private function licence(): string
    {
        return (string) config('api.licence');
    }

    private function derniereMaj(): ?string
    {
        $derniere = $this->maxDate([
            Projet::query()->max('updated_at'),
            Verification::query()->max('updated_at'),
            Contribution::query()->max('updated_at'),
            Commune::query()->max('updated_at'),
        ]);

        return $derniere !== null ? Carbon::parse($derniere)->toISOString() : null;
    }

    private function derniereMajRegion(string $label): ?string
    {
        $values = $this->regionValues($label);

        $derniere = $this->maxDate([
            Commune::query()->whereIn('region', $values)->max('updated_at'),
            Projet::query()
                ->whereHas('communes', fn (Builder $q) => $q->whereIn('region', $values))
                ->max('updated_at'),
        ]);

        return $derniere !== null ? Carbon::parse($derniere)->toISOString() : null;
    }

    /**
     * @param  array<int, string|null>  $dates
     */
    private function maxDate(array $dates): ?string
    {
        $dates = array_values(array_filter($dates, fn ($d) => $d !== null && $d !== ''));

        return $dates === [] ? null : max($dates);
    }

    /**
     * Injecte la provenance dans chaque projet d'une synthèse (commune,
     * département) et ajoute licence + dernière mise à jour à la fiche.
     *
     * @param  array<string, mixed>  $fiche
     * @return array<string, mixed>
     */
    private function avecProvenance(array $fiche): array
    {
        $ids = collect($fiche['projets'] ?? [])->pluck('projet_id')->filter()->values();

        $projets = Projet::query()->whereIn('id', $ids)->get()->keyBy('id');
        $verifies = Verification::query()->whereIn('projet_id', $ids)->get()->keyBy('projet_id');
        $enrichis = $this->enrichissements($ids);

        $fiche['projets'] = collect($fiche['projets'] ?? [])->map(function (array $projet) use ($projets, $verifies, $enrichis) {
            $id = $projet['projet_id'];
            $modele = $projets->get($id);
            if ($modele === null) {
                return $projet;
            }

            $verification = $verifies->get($id);
            $enrichi = $enrichis->get($id);

            return array_merge($projet, [
                'statut_verification' => $this->statutVerification($verification?->etat),
                'provenance' => $this->provenance($modele, $verification, (int) ($enrichi['n'] ?? 0), $enrichi['derniere'] ?? null),
            ]);
        })->all();

        $fiche['licence'] = $this->licence();
        $fiche['derniere_mise_a_jour'] = $ids->isEmpty()
            ? null
            : Carbon::parse((string) Projet::query()->whereIn('id', $ids)->max('updated_at'))->toISOString();

        return $fiche;
    }

    /**
     * La localisation devient la géométrie GeoJSON : elle sort des
     * propriétés pour ne pas être dupliquée.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function sansLocalisation(array $item): array
    {
        unset($item['localisation']);

        return $item;
    }
}
