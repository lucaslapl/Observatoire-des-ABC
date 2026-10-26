<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ApiV1Service;
use Illuminate\Http\JsonResponse;

class DepartementsController extends Controller
{
    public function __construct(private ApiV1Service $api) {}

    /**
     * Synthèse d'un département : communes et projets couverts,
     * provenance de chaque projet.
     *
     * @group Départements
     *
     * @urlParam code string required Code du département (ex. 56, 2a, 972).
     */
    public function show(string $code): JsonResponse
    {
        return $this->json(['data' => $this->api->departement($code)]);
    }
}
