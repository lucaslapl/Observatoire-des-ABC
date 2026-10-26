<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ApiV1Service;
use Illuminate\Http\JsonResponse;

class RegionsController extends Controller
{
    public function __construct(private ApiV1Service $api) {}

    /**
     * Synthèse d'une région : départements, communes et projets couverts.
     *
     * @group Régions
     *
     * @urlParam code string required Slug (ex. bretagne), code INSEE (ex. 53) ou libellé (ex. Bretagne).
     */
    public function show(string $code): JsonResponse
    {
        return $this->json(['data' => $this->api->region($code)]);
    }
}
