<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StatsService;

class StatsController extends Controller
{
    public function index(StatsService $service): array
    {
        return $service->stats();
    }
}
