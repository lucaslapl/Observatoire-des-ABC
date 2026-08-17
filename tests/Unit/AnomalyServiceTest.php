<?php

namespace Tests\Unit;

use App\Models\Commune;
use App\Models\Projet;
use App\Services\AnomalyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnomalyServiceTest extends TestCase
{
    use RefreshDatabase;

    private AnomalyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AnomalyService;
    }

    public function test_haversine_known_distance(): void
    {
        // Paris (48.8566, 2.3522) → Lyon (45.7640, 4.8357) ≈ 392 km.
        $d = $this->service->haversineKm(48.8566, 2.3522, 45.7640, 4.8357);
        $this->assertGreaterThan(390, $d);
        $this->assertLessThan(395, $d);
    }

    public function test_haversine_zero_distance(): void
    {
        $this->assertEqualsWithDelta(0.0, $this->service->haversineKm(48.0, 2.0, 48.0, 2.0), 0.001);
    }

    public function test_compute_anomalies_detects_outlier_commune(): void
    {
        $projet = Projet::create([
            'id' => 'abc-test',
            'nom' => 'ABC Test',
            'structure_porteuse' => 'Ville Test',
            'annee_debut' => 2020,
            'statut' => 'en_cours',
            'source' => 'datagouv',
        ]);

        // Deux communes proches (centre de la France) + une à ~550 km.
        Commune::insert([
            ['projet_id' => 'abc-test', 'code_geographique' => '10000', 'libelle_geographique' => 'A', 'lon' => 2.35, 'lat' => 48.85, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => 'abc-test', 'code_geographique' => '20000', 'libelle_geographique' => 'B', 'lon' => 2.40, 'lat' => 48.80, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => 'abc-test', 'code_geographique' => '30000', 'libelle_geographique' => 'C', 'lon' => -4.50, 'lat' => 48.40, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $n = $this->service->computeAnomalies();
        $this->assertSame(1, $n);

        $flagged = Commune::where('code_geographique', '30000')->first();
        $this->assertTrue((bool) $flagged->anomalie);
        $this->assertGreaterThan(100, $flagged->distance_centre_km);

        $ok = Commune::where('code_geographique', '10000')->first();
        $this->assertFalse((bool) $ok->anomalie);
        $this->assertNotNull($ok->distance_centre_km);
    }

    public function test_distance_rounded_to_0_1_km(): void
    {
        $projet = Projet::create([
            'id' => 'abc-test2',
            'nom' => 'ABC Test2',
            'statut' => 'en_cours',
            'source' => 'datagouv',
        ]);
        Commune::insert([
            ['projet_id' => 'abc-test2', 'code_geographique' => '10000', 'libelle_geographique' => 'A', 'lon' => 2.35, 'lat' => 48.85, 'created_at' => now(), 'updated_at' => now()],
            ['projet_id' => 'abc-test2', 'code_geographique' => '20000', 'libelle_geographique' => 'B', 'lon' => 2.40, 'lat' => 48.80, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service->computeAnomalies();
        $a = Commune::where('code_geographique', '10000')->first();

        $this->assertSame(round((float) $a->distance_centre_km * 10) / 10, (float) $a->distance_centre_km);
    }
}
