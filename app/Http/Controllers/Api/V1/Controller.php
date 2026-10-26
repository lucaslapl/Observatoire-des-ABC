<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller extends \App\Http\Controllers\Controller
{
    /**
     * Négociation de format : paramètre format=geojson|json, sinon l'en-tête
     * Accept (application/geo+json). JSON par défaut.
     */
    protected function wantsGeoJson(Request $request): bool
    {
        $format = $request->input('format');

        if (is_string($format) && $format !== '') {
            return strtolower($format) === 'geojson';
        }

        return str_contains(strtolower((string) $request->header('Accept')), 'geo+json');
    }

    protected function json(array $payload, bool $geojson = false): JsonResponse
    {
        return response()->json($payload)->header(
            'Content-Type',
            $geojson ? 'application/geo+json' : 'application/json'
        );
    }
}
