<?php

namespace Tests\Unit;

use App\Models\Commune;
use App\Models\GeoCache;
use App\Models\Projet;
use App\Services\GeocodeService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\ConnectionException;
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
                'population' => 104885,
                'surface' => 1499.47,
            ]),
        ]);

        $result = app(GeocodeService::class)->enrichGeocoding();

        $this->assertSame(1, $result['distinct']);
        $this->assertSame(1, $result['fetched']);
        $this->assertSame(1, $result['updated']);

        $cache = GeoCache::find('54395');
        $this->assertSame(6.18, (float) $cache->lon);
        $this->assertSame('Nancy', $cache->name);
        $this->assertSame(104885, $cache->population);
        $this->assertSame(14.99, (float) $cache->surface_km2);

        $commune = Commune::where('code_geographique', '54395')->first();
        $this->assertSame(6.18, (float) $commune->lon);
        $this->assertSame(48.69, (float) $commune->lat);
        $this->assertSame(104885, $commune->population);
        $this->assertSame(14.99, (float) $commune->surface_km2);
    }

    public function test_rattrape_les_codes_geocodes_avant_les_colonnes_demographiques(): void
    {
        // Cache préexistant : coordonnées connues, population absente
        // (géocodé avant l'ajout des colonnes démographiques).
        GeoCache::create([
            'code_geographique' => '54395',
            'lon' => 6.18,
            'lat' => 48.69,
            'name' => 'Nancy',
        ]);

        $projet = Projet::create([
            'id' => 'abc-geo-backfill',
            'nom' => 'ABC Backfill',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '54395',
            'libelle_geographique' => 'Nancy',
            'lon' => 6.18,
            'lat' => 48.69,
        ]);

        Http::fake([
            'geo.api.gouv.fr/communes/54395*' => Http::response([
                'name' => 'Nancy',
                'centre' => ['type' => 'Point', 'coordinates' => [6.18, 48.69]],
                'population' => 104885,
                'surface' => 1499.47,
            ]),
        ]);

        $result = app(GeocodeService::class)->enrichGeocoding();

        $this->assertSame(1, $result['fetched']);
        $this->assertSame(104885, GeoCache::find('54395')->population);
        $this->assertSame(104885, Commune::where('code_geographique', '54395')->first()->population);
    }

    public function test_erreur_reseau_transitoire_ne_marque_pas_le_code(): void
    {
        $projet = Projet::create([
            'id' => 'abc-geo-timeout',
            'nom' => 'ABC Timeout',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '54395',
            'libelle_geographique' => 'Nancy',
        ]);

        // Dans un pool, une erreur réseau atterrit dans les résultats comme
        // une exception à la place de la réponse.
        Http::fake(function () {
            throw new ConnectionException('timeout simulé');
        });

        $result = app(GeocodeService::class)->enrichGeocoding();

        // Rien n'est écrit : le code sera retenté au prochain passage.
        $this->assertSame(1, $result['fetched']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, GeoCache::count());
    }

    public function test_ne_retente_pas_les_codes_inconnus_de_lapi(): void
    {
        // Code marqué lon=0/lat=0 (inconnu ou outre-mer) : aucun besoin de
        // le re-demander à l'API pour une population qui n'existera pas.
        GeoCache::create([
            'code_geographique' => '97111',
            'lon' => 0,
            'lat' => 0,
        ]);

        $projet = Projet::create([
            'id' => 'abc-geo-outremer',
            'nom' => 'ABC Outre-mer',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '97111',
            'libelle_geographique' => 'Capesterre-Belle-Eau',
        ]);

        Http::fake([]);

        $result = app(GeocodeService::class)->enrichGeocoding();

        $this->assertSame(0, $result['fetched']);
        $this->assertSame(0, $result['updated']);
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
