<?php

namespace App\Services;

use App\Models\Commune;
use App\Models\GeoCache;
use App\Support\ApiCache;
use Illuminate\Support\Facades\Http;

/**
 * Géocodage via geo.api.gouv.fr avec cache (équivalent de src/geocode.ts).
 * Outre-mer non couvert → lon=0, lat=0 (conservé tel quel pour la parité du
 * filtre « géolocalisé »).
 *
 * La même requête apporte la démographie du code INSEE : population
 * (populations légales, recensement INSEE) et superficie (IGN Admin Express,
 * hectares côté API, stockée en km²).
 * Les valeurs sont durables dans geo_cache puis dénormalisées sur communes,
 * comme les coordonnées.
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
            $g = $cached->get($r->code_geographique);
            // Codes jamais géocodés, ou géocodés avant l'ajout des colonnes
            // démographiques (population absente du cache) : récupérés une
            // fois pour obtenir population et superficie. Les codes inconnus
            // de l'API (lon=0, lat=0) ne sont pas retentés.
            if ($g === null
                || ($g->population === null && ($g->lon != 0 || $g->lat != 0))) {
                $toFetch[] = $r->code_geographique;
            }
        }

        $this->fetchParallel($toFetch);

        $updated = 0;
        foreach ($rows as $r) {
            $g = GeoCache::find($r->code_geographique);
            if ($g && ($g->lon || $g->lat)) {
                Commune::where('code_geographique', $r->code_geographique)
                    ->update([
                        'lon' => $g->lon,
                        'lat' => $g->lat,
                        'population' => $g->population,
                        'surface_km2' => $g->surface_km2,
                    ]);
                $updated++;
            }
        }

        // Géocodage écrit en masse (sans observateurs) : invalidation
        // explicite du cache applicatif de l'API v1.
        ApiCache::invalidate();

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
                    ->get("https://geo.api.gouv.fr/communes/{$code}?fields=centre,name,population,surface"),
                $chunk,
            ));

            foreach ($chunk as $i => $code) {
                $res = $responses[$i] ?? null;
                // Erreur réseau transitoire : le pool renvoie l'exception à la
                // place de la réponse. On n'écrit rien — un marquage lon=0/lat=0
                // blacklisterait le code à tort, le prochain passage retentera.
                if ($res instanceof \Throwable) {
                    continue;
                }
                if ($res && $res->successful()) {
                    $j = $res->json();
                    $updates = ['lon' => 0, 'lat' => 0];
                    if (isset($j['centre']['coordinates'])) {
                        $updates['lon'] = $j['centre']['coordinates'][0];
                        $updates['lat'] = $j['centre']['coordinates'][1];
                    }
                    if (isset($j['name'])) {
                        $updates['name'] = $j['name'];
                    }
                    if (isset($j['population'])) {
                        $updates['population'] = (int) $j['population'];
                    }
                    // L'API exprime la surface en hectares.
                    if (isset($j['surface'])) {
                        $updates['surface_km2'] = round(((float) $j['surface']) / 100, 2);
                    }
                    GeoCache::updateOrCreate(
                        ['code_geographique' => $code],
                        $updates,
                    );
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
