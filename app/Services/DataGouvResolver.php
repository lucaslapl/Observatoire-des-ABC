<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Résolution de la dernière version d'une ressource data.gouv.
 *
 * Les URLs pinnées dans config('abc.sources') datent du jour où elles ont été
 * copiées : quand l'OFB ou le MTEC republient un CSV, l'URL pinnée reste sur
 * l'ancienne révision. On interroge l'API data.gouv pour retrouver la
 * ressource actuelle, avec repli sur l'URL pinnée en cas d'échec.
 */
class DataGouvResolver
{
    private const API = 'https://www.data.gouv.fr/api/1/datasets/';

    /**
     * URL de téléchargement pour une clé de config('abc.sources').
     * Retourne toujours une URL utilisable : l'URL pinnée en dernier recours.
     */
    public function resolve(string $key): string
    {
        $pinned = (string) config("abc.sources.{$key}");

        if (! config('abc.auto_resolve_sources')) {
            return $pinned;
        }

        $spec = config("abc.source_resolution.{$key}");
        if (! is_array($spec) || empty($spec['dataset']) || empty($spec['match'])) {
            return $pinned;
        }

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->withHeaders(['User-Agent' => config('abc.user_agent')])
                ->get(self::API.$spec['dataset'].'/');

            if (! $response->successful()) {
                return $pinned;
            }

            $resources = collect($response->json('resources') ?? [])
                ->filter(fn ($r) => ($r['format'] ?? '') === 'csv')
                ->filter(fn ($r) => str_contains(mb_strtolower((string) $r['url']), mb_strtolower((string) $spec['match'])))
                ->sortByDesc(fn ($r) => $r['last_modified'] ?? $r['created_at'] ?? '')
                ->values();

            return (string) ($resources->first()['url'] ?? $pinned);
        } catch (\Throwable) {
            return $pinned;
        }
    }
}
