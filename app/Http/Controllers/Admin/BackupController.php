<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\BackupService;
use Illuminate\Http\JsonResponse;

class BackupController extends Controller
{
    public function store(BackupService $service): JsonResponse
    {
        try {
            $r = $service->backupDb();

            return response()->json(['ok' => true, 'path' => $r['path'], 'kept' => $r['kept']]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
