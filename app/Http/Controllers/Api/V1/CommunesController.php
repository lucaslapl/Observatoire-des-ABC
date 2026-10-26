<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ApiV1Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommunesController extends Controller
{
    public function __construct(private ApiV1Service $api) {}

    /**
     * Fiche d'une commune : localisation, EPCI, projets liés (avec
     * provenance de chaque projet). Format GeoJSON avec `format=geojson`
     * ou `Accept: application/geo+json` (une feature par projet lié).
     *
     * @group Communes
     *
     * @urlParam code string required Code INSEE de la commune (ex. 56003).
     */
    public function show(Request $request, string $code): JsonResponse
    {
        $request->validate(['format' => 'nullable|in:json,geojson'], [
            'format.in' => 'Le paramètre format doit valoir json ou geojson.',
        ]);

        if ($this->wantsGeoJson($request)) {
            return $this->json($this->api->communeGeoJson($code), geojson: true);
        }

        return $this->json(['data' => $this->api->commune($code)]);
    }
}
