<?php

namespace App\Services;

use App\Models\Commune;
use App\Models\GeoCache;
use Illuminate\Support\Facades\Http;

/**
 * Géocodage via geo.api.gouv.fr avec cache (équivalent de src/geocode.ts).
 * Outre-mer non couvert → lon=0, lat=0 (conservé tel quel pour la parité du
 * filtre « géolocalisé »).
 */
class GeocodeService
{
    public function enrichGeocoding(): array
    {
        $rows = Commune::query()
            ->select('code_geographique')
            ->distinct()
            ->whereNotNull('code_geographique')
            ->where('code_geographique', '!=', '')
            ->get();

        $cached = GeoCache::all()->keyBy('code_geographique');

        $toFetch = [];
        foreach ($rows as $r) {
            if (! $cached->has($r->code_geographique)) {
                $toFetch[] = $r->code_geographique;
            }
        }

        $this->fetchParallel($toFetch);

        $updated = 0;
        foreach ($rows as $r) {
            $g = GeoCache::find($r->code_geographique);
            if ($g && ($g->lon || $g->lat)) {
                Commune::where('code_geographique', $r->code_geographique)
                    ->update(['lon' => $g->lon, 'lat' => $g->lat]);
                $updated++;
            }
        }

        return [
            'distinct' => $rows->count(),
            'fetched' => count($toFetch),
            'updated' => $updated,
        ];
    }

    /**
     * Récupération avec un pool de 12 workers (équivalent de CONCURRENCY = 12).
     */
    private function fetchParallel(array $codes): void
    {
        $chunks = array_chunk($codes, 12);
        foreach ($chunks as $chunk) {
            $responses = Http::pool(fn ($pool) => array_map(
                fn ($code) => $pool->acceptJson()
                    ->withHeaders(['User-Agent' => config('abc.user_agent')])
                    ->get("https://geo.api.gouv.fr/communes/{$code}?fields=centre,name"),
                $chunk,
            ));

            foreach ($chunk as $i => $code) {
                $res = $responses[$i] ?? null;
                if ($res && $res->successful()) {
                    $j = $res->json();
                    if (isset($j['centre']['coordinates'])) {
                        GeoCache::updateOrCreate(
                            ['code_geographique' => $code],
                            [
                                'lon' => $j['centre']['coordinates'][0],
                                'lat' => $j['centre']['coordinates'][1],
                                'name' => $j['name'] ?? null,
                            ],
                        );
                    } else {
                        // Inconnu ou outre-mer non couvert : on laisse vide.
                        GeoCache::updateOrCreate(
                            ['code_geographique' => $code],
                            ['lon' => 0, 'lat' => 0],
                        );
                    }
                } else {
                    GeoCache::updateOrCreate(
                        ['code_geographique' => $code],
                        ['lon' => 0, 'lat' => 0],
                    );
                }
            }
        }
    }
}
