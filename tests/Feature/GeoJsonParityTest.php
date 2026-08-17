<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use App\Models\Verification;
use App\Services\GeoJsonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoJsonParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_geojson_excludes_zero_coordinates(): void
    {
        $projet = Projet::create([
            'id' => 'abc-zero',
            'nom' => 'ABC Zero',
            'statut' => 'en_cours',
            'source' => 'datagouv',
        ]);
        Commune::create(['projet_id' => 'abc-zero', 'code_geographique' => '10001', 'libelle_geographique' => 'A', 'lon' => 0, 'lat' => 0]);
        Commune::create(['projet_id' => 'abc-zero', 'code_geographique' => '10002', 'libelle_geographique' => 'B', 'lon' => 2.35, 'lat' => 48.85]);

        $features = app(GeoJsonService::class)->buildGeoJson()['features'];
        $this->assertCount(1, $features);
        $this->assertSame('10002', $features[0]['properties']['code_commune']);
    }

    public function test_geojson_coordinates_are_lon_lat(): void
    {
        $projet = Projet::create([
            'id' => 'abc-coords',
            'nom' => 'ABC Coords',
            'statut' => 'termine',
            'source' => 'datagouv',
        ]);
        Commune::create(['projet_id' => 'abc-coords', 'code_geographique' => '10003', 'libelle_geographique' => 'A', 'lon' => -1.55, 'lat' => 47.20]);

        $feature = app(GeoJsonService::class)->buildGeoJson()['features'][0];
        $this->assertSame([-1.55, 47.20], $feature['geometry']['coordinates']);
    }

    public function test_geojson_statut_affichage_overrides_statut(): void
    {
        $projet = Projet::create([
            'id' => 'abc-verif',
            'nom' => 'ABC Verif',
            'statut' => 'inconnu',
            'source' => 'fondsvert-p113-2025',
        ]);
        Commune::create(['projet_id' => 'abc-verif', 'code_geographique' => '10004', 'libelle_geographique' => 'A', 'lon' => 2.35, 'lat' => 48.85]);
        Verification::create(['projet_id' => 'abc-verif', 'etat' => 'confirme_en_cours']);

        $feature = app(GeoJsonService::class)->buildGeoJson()['features'][0];
        $this->assertSame('en_cours', $feature['properties']['statut_affichage']);
        $this->assertSame('En cours', $feature['properties']['categorie']);
        $this->assertTrue($feature['properties']['verifie']);
    }

    public function test_geojson_donnees_2022_from_wayback_source(): void
    {
        $projet = Projet::create([
            'id' => 'abc-wayback',
            'nom' => 'ABC Wayback',
            'statut' => 'a_venir',
            'source' => 'wayback',
        ]);
        Commune::create(['projet_id' => 'abc-wayback', 'code_geographique' => '10005', 'libelle_geographique' => 'A', 'lon' => 2.35, 'lat' => 48.85]);

        $feature = app(GeoJsonService::class)->buildGeoJson()['features'][0];
        $this->assertTrue($feature['properties']['donnees_2022']);
    }

    public function test_geojson_meta_endpoint(): void
    {
        $projet = Projet::create([
            'id' => 'abc-meta',
            'nom' => 'ABC Meta',
            'statut' => 'en_cours',
            'source' => 'datagouv',
            'potentiellement_termine' => true,
        ]);

        $response = $this->getJson('/api/meta');
        $response->assertOk();
        $response->assertJsonPath('countProjets', 1);
        $response->assertJsonPath('countPotentiellementTermines', 1);
        $response->assertJsonPath('stats.0.statut', 'en_cours');
        $this->assertArrayHasKey('sources', $response->json());
    }

    public function test_verification_list_endpoint(): void
    {
        $projet = Projet::create([
            'id' => 'abc-verify-list',
            'nom' => 'ABC À Vérifier',
            'statut' => 'en_cours',
            'source' => 'datagouv',
            'annee_debut' => null,
            'potentiellement_en_cours' => true,
        ]);
        Commune::create(['projet_id' => 'abc-verify-list', 'code_geographique' => '10006', 'libelle_geographique' => 'Saint Jean', 'lon' => 2.35, 'lat' => 48.85]);

        $response = $this->getJson('/api/verifications');
        $response->assertOk();

        $projet = $response->json('projets.0');
        $this->assertSame('abc-verify-list', $projet['id']);
        $this->assertStringContainsString('potentiellement en cours', $projet['motifs'][0]);
        $this->assertStringContainsString('date inconnue', $projet['motifs'][1]);
        $this->assertSame('a_verifier', $projet['etat']);
        $this->assertSame('"atlas de la biodiversité communale" "À Vérifier" Saint Jean', $projet['requete']);
    }
}
