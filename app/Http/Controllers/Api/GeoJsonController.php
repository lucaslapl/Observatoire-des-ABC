<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commune;
use App\Models\Projet;
use App\Services\GeoJsonService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class GeoJsonController extends Controller
{
    /**
     * GeoJSON public (~4 Mo). Le jeu de données ne change qu'au collect
     * mensuel ou après une vérification : on sert un ETag dérivé de l'état
     * des tables, valable une heure côté navigateur, et un 304 immédiat si
     * l'ETag correspond.
     */
    public function index(GeoJsonService $service, Request $request): Response
    {
        $etag = $this->etag();

        if (trim((string) $request->header('if-none-match')) === $etag) {
            return response()->noContent(304)
                ->setEtag($etag)
                ->header('Cache-Control', 'public, max-age=3600');
        }

        return response()->json($service->buildGeoJson())
            ->setEtag($etag)
            ->header('Cache-Control', 'public, max-age=3600');
    }

    private function etag(): string
    {
        return '"'.md5(implode('|', [
            Projet::max('updated_at'),
            Projet::count(),
            Commune::max('updated_at'),
            Commune::count(),
        ])).'"';
    }
}
