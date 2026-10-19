<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoJsonCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_geojson_serves_etag_and_cache_control(): void
    {
        $projet = Projet::create([
            'id' => 'abc-cache',
            'nom' => 'ABC Cache',
            'statut' => 'en_cours',
            'source' => 'datagouv',
        ]);
        Commune::create(['projet_id' => 'abc-cache', 'code_geographique' => '10010', 'libelle_geographique' => 'A', 'lon' => 2.35, 'lat' => 48.85]);

        $response = $this->getJson('/api/abc.geojson');
        $response->assertOk();
        $this->assertNotEmpty($response->getEtag());

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('max-age=3600', $cacheControl);
        $this->assertStringContainsString('public', $cacheControl);
    }

    public function test_geojson_returns_304_on_matching_if_none_match(): void
    {
        $projet = Projet::create([
            'id' => 'abc-304',
            'nom' => 'ABC 304',
            'statut' => 'termine',
            'source' => 'datagouv',
        ]);
        Commune::create(['projet_id' => 'abc-304', 'code_geographique' => '10011', 'libelle_geographique' => 'B', 'lon' => 2.35, 'lat' => 48.85]);

        $etag = $this->getJson('/api/abc.geojson')->getEtag();

        $response = $this->getJson('/api/abc.geojson', ['If-None-Match' => $etag]);
        $response->assertStatus(304);
        $this->assertSame($etag, $response->getEtag());
    }

    public function test_geojson_etag_changes_when_data_changes(): void
    {
        $projet = Projet::create([
            'id' => 'abc-etag',
            'nom' => 'ABC Etag',
            'statut' => 'en_cours',
            'source' => 'datagouv',
        ]);
        Commune::create(['projet_id' => 'abc-etag', 'code_geographique' => '10012', 'libelle_geographique' => 'C', 'lon' => 2.35, 'lat' => 48.85]);

        $etag = $this->getJson('/api/abc.geojson')->getEtag();

        // Les ETags sont dérivés de updated_at : on avance le temps pour
        // garantir que le second Etag diffère du premier.
        $this->travel(2)->seconds();
        $projet->update(['statut' => 'termine']);

        $newEtag = $this->getJson('/api/abc.geojson')->getEtag();
        $this->assertNotSame($etag, $newEtag);
    }
}
