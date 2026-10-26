<?php

namespace Tests\Feature;

use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1ThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_throttling_est_de_60_requetes_par_minute_et_par_ip(): void
    {
        Projet::create([
            'id' => 'abc-throttle',
            'nom' => 'ABC Throttle',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        // 60 requêtes : autorisées (les réponses sont en cache applicatif,
        // le compteur de throttling tourne sur le cache, pas la base).
        $this->getJson('/api/v1/stats')->assertOk();

        for ($i = 0; $i < 59; $i++) {
            $this->getJson('/api/v1/stats');
        }

        // 61e requête dans la même minute → 429.
        $response = $this->getJson('/api/v1/stats');
        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
    }
}
