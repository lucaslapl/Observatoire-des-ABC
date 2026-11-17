<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SitemapService;
use Illuminate\Http\JsonResponse;

/**
 * Régénération du sitemap depuis le panneau d'administration
 * (équivalent HTTP de abc:sitemap:flush, pour les hébergements sans
 * accès shell).
 */
class SitemapController extends Controller
{
    public function store(SitemapService $sitemap): JsonResponse
    {
        try {
            $r = $sitemap->flush();

            return response()->json([
                'ok' => true,
                'total' => $r['total'],
                'sections' => $r['sections'],
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
