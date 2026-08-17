<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CollectService;
use Illuminate\Http\JsonResponse;

class CollectController extends Controller
{
    public function store(CollectService $service): JsonResponse
    {
        try {
            $summary = $service->collectAll();

            return response()->json(['ok' => true, 'summary' => $summary]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
