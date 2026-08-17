<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatsService;

class MetaController extends Controller
{
    public function index(StatsService $service): array
    {
        return $service->meta();
    }
}
