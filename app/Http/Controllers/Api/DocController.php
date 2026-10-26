<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * Documentation publique de l'API v1 : pages statiques générées par
 * Scribe (`php artisan scribe:generate`, sortie dans public/docs).
 * Servies telles quelles : la production n'a pas besoin de Scribe.
 */
class DocController extends Controller
{
    public function show(): Response
    {
        return $this->file('index.html');
    }

    public function spec(): Response
    {
        return $this->file('openapi.yaml', 'application/yaml; charset=utf-8');
    }

    private function file(string $name, ?string $contentType = null): Response
    {
        $path = public_path('docs/'.$name);

        abort_unless(is_file($path), 404);

        return response()->file($path, array_filter([
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=3600',
        ]));
    }
}
