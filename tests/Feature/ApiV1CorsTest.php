<?php

namespace Tests\Feature;

use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiV1CorsTest extends TestCase
{
    use RefreshDatabase;

    public function test_les_origines_tierces_peuvent_interroger_lapi_v1(): void
    {
        Projet::create([
            'id' => 'abc-cors',
            'nom' => 'ABC Cors',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        // Préflight OPTIONS d'un site tiers.
        $preflight = $this->call('OPTIONS', '/api/v1/projets', [], [], [], [
            'HTTP_ORIGIN' => 'https://carte-tiers.example.org',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
        $preflight->assertStatus(204);
        $this->assertSame('*', $preflight->headers->get('Access-Control-Allow-Origin'));

        // Requête réelle : CORS appliqué + ETag exposé aux navigateurs.
        $response = $this->getJson('/api/v1/projets', [
            'Origin' => 'https://carte-tiers.example.org',
        ]);
        $response->assertOk();
        $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('ETag', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_lapi_historique_ne_gagne_pas_cors(): void
    {
        // L'API non versionnée sert le site lui-même : pas de changement
        // de comportement pour elle.
        $response = $this->getJson('/api/meta', [
            'Origin' => 'https://carte-tiers.example.org',
        ]);
        $response->assertOk();
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
