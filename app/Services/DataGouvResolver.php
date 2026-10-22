<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Résolution de la dernière version d'une ressource data.gouv.
 *
 * Les URLs pinnées dans config('abc.sources') datent du jour où elles ont été
 * copiées : quand l'OFB ou le MTEC republient un CSV, l'URL pinnée reste sur
 * l'ancienne révision. On interroge l'API data.gouv pour retrouver la
 * ressource actuelle, avec repli sur l'URL pinnée en cas d'échec (chaque
 * repli est journalisé : une source qui suivrait indéfiniment une vieille
 * révision doit être visible).
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
                $this->repli($key, "API data.gouv HTTP {$response->status()}", $pinned);

                return $pinned;
            }

            $resources = collect($response->json('resources') ?? [])
                ->filter(fn ($r) => ($r['format'] ?? '') === 'csv')
                ->filter(fn ($r) => str_contains(mb_strtolower((string) $r['url']), mb_strtolower((string) $spec['match'])))
                ->sortByDesc(fn ($r) => $r['last_modified'] ?? $r['created_at'] ?? '')
                ->values();

            if ($resources->isEmpty()) {
                $this->repli($key, 'aucune ressource CSV ne correspond au motif', $pinned);

                return $pinned;
            }

            return (string) ($resources->first()['url'] ?? $pinned);
        } catch (\Throwable $e) {
            $this->repli($key, $e->getMessage(), $pinned);

            return $pinned;
        }
    }

    /**
     * Journalise un repli sur l'URL pinnée (révision potentiellement
     * obsolète) : jamais silencieux.
     */
    private function repli(string $key, string $raison, string $pinned): void
    {
        Log::warning("Résolution data.gouv a échoué pour « {$key} » ({$raison}) : repli sur l'URL pinnée (révision possiblement obsolète) {$pinned}");
    }
}
