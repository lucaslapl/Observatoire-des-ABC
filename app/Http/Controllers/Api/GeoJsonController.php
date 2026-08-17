<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GeoJsonService;

class GeoJsonController extends Controller
{
    public function index(GeoJsonService $service): array
    {
        return $service->buildGeoJson();
    }
}
