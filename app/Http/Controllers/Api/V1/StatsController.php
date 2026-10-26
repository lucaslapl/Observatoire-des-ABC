<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\ApiV1Service;
use Illuminate\Http\JsonResponse;

class StatsController extends Controller
{
    public function __construct(private ApiV1Service $api) {}

    /**
     * Compteurs globaux : projets par statut, communes couvertes,
     * projets vérifiés, anomalies, contributions en attente.
     *
     * @group Statistiques
     */
    public function index(): JsonResponse
    {
        return $this->json(['data' => $this->api->stats()]);
    }
}
