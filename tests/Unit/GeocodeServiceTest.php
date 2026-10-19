<?php

namespace Tests\Unit;

use App\Models\Commune;
use App\Models\GeoCache;
use App\Models\Projet;
use App\Services\GeocodeService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeocodeServiceTest extends TestCase
{
    use DatabaseMigrations;

    public function test_enriches_missing_communes_from_api(): void
    {
        $projet = Projet::create([
            'id' => 'abc-geo',
            'nom' => 'ABC Geo',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '54395',
            'libelle_geographique' => 'Nancy',
        ]);

        Http::fake([
            'geo.api.gouv.fr/communes/54395*' => Http::response([
                'name' => 'Nancy',
                'centre' => ['type' => 'Point', 'coordinates' => [6.18, 48.69]],
            ]),
        ]);

        $result = app(GeocodeService::class)->enrichGeocoding();

        $this->assertSame(1, $result['distinct']);
        $this->assertSame(1, $result['fetched']);
        $this->assertSame(1, $result['updated']);

        $cache = GeoCache::find('54395');
        $this->assertSame(6.18, (float) $cache->lon);
        $this->assertSame('Nancy', $cache->name);

        $commune = Commune::where('code_geographique', '54395')->first();
        $this->assertSame(6.18, (float) $commune->lon);
        $this->assertSame(48.69, (float) $commune->lat);
    }

    public function test_failed_lookup_marks_zero_and_does_not_crash(): void
    {
        $projet = Projet::create([
            'id' => 'abc-geo-ko',
            'nom' => 'ABC Geo KO',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '99999',
        ]);

        Http::fake([
            'geo.api.gouv.fr/communes/99999*' => Http::response(null, 404),
        ]);

        $result = app(GeocodeService::class)->enrichGeocoding();

        $this->assertSame(1, $result['fetched']);
        $this->assertSame(0, $result['updated']);
        $this->assertDatabaseHas('geo_cache', ['code_geographique' => '99999', 'lon' => 0]);
    }
}
