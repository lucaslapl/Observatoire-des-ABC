<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ApiV1Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjetsController extends Controller
{
    /** Messages de validation en français (API publique française). */
    private const MESSAGES = [
        'statut.in' => 'Le filtre statut doit valoir a_venir, en_cours, termine ou inconnu.',
        'annee.integer' => 'Le filtre annee doit être une année (entier).',
        'annee.between' => 'Le filtre annee doit être compris entre 1990 et 2100.',
        'code_insee.regex' => 'Le filtre code_insee doit être un code INSEE à 5 caractères.',
        'departement.regex' => 'Le filtre departement doit être un code de 1 à 3 caractères (ex. 56, 2a, 972).',
        'porteur.max' => 'Le filtre porteur doit faire au plus 200 caractères.',
        'bbox.regex' => 'Le filtre bbox doit être « lon_min,lat_min,lon_max,lat_max » (4 nombres décimaux).',
        'per_page.integer' => 'per_page doit être un entier.',
        'per_page.between' => 'per_page doit être compris entre 1 et 100.',
        'format.in' => 'Le paramètre format doit valoir json ou geojson.',
    ];

    public function __construct(private ApiV1Service $api) {}

    /**
     * Liste des projets ABC.
     *
     * Paginée par curseur (`meta.next_cursor` / `meta.next_page_url`).
     * Filtres : `statut` (a_venir, en_cours, termine, inconnu — statut
     * affiché, le verdict de vérification prime), `annee` (année de
     * début), `code_insee`, `departement` (ex. 56, 2a, 972), `region`
     * (slug, code INSEE ou libellé), `porteur` (recherche partielle),
     * `bbox` (`lon_min,lat_min,lon_max,lat_max`, WGS84).
     * Format GeoJSON avec `format=geojson` ou `Accept: application/geo+json`.
     *
     * @group Projets
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'statut' => 'nullable|in:a_venir,en_cours,termine,inconnu',
            'annee' => 'nullable|integer|min:1990|max:2100',
            'code_insee' => ['nullable', 'regex:/^[0-9A-Z]{5}$/i'],
            'departement' => ['nullable', 'regex:/^[0-9a-zA-Z]{1,3}$/'],
            'region' => 'nullable|string|max:60',
            'porteur' => 'nullable|string|max:200',
            'bbox' => ['nullable', 'regex:/^-?\d{1,3}(?:\.\d+)?,\s*-?\d{1,2}(?:\.\d+)?,\s*-?\d{1,3}(?:\.\d+)?,\s*-?\d{1,2}(?:\.\d+)?$/'],
            'per_page' => 'nullable|integer|min:1|max:100',
            'format' => 'nullable|in:json,geojson',
        ], self::MESSAGES);

        $filters = [
            'statut' => $validated['statut'] ?? null,
            'annee' => isset($validated['annee']) ? (int) $validated['annee'] : null,
            'code_insee' => isset($validated['code_insee']) ? strtoupper($validated['code_insee']) : null,
            'departement' => $validated['departement'] ?? null,
            'region' => $validated['region'] ?? null,
            'porteur' => $validated['porteur'] ?? null,
        ];

        if (isset($validated['bbox'])) {
            $filters['bbox'] = array_map('floatval', explode(',', str_replace(' ', '', $validated['bbox'])));
        }

        $perPage = $this->perPage($validated);
        $geojson = $this->wantsGeoJson($request);

        $payload = $geojson
            ? $this->api->projetsGeoJson($filters, $perPage)
            : $this->api->projets($filters, $perPage);

        return $this->json($payload, geojson: $geojson);
    }

    /**
     * Fiche détaillée d'un projet : communes, vérification, enrichissements,
     * historique complet (auteurs crédités collectivement), provenance.
     *
     * @group Projets
     *
     * @urlParam id string required Identifiant du projet (ex. abc-albi-albi-2017).
     */
    public function show(string $id): JsonResponse
    {
        return $this->json(['data' => $this->api->projet($id)]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function perPage(array $validated): int
    {
        $perPage = isset($validated['per_page']) ? (int) $validated['per_page'] : (int) config('api.per_page');

        return min($perPage, (int) config('api.max_per_page'));
    }
}
