<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ErrorPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_url_renders_inertia_error_page(): void
    {
        $response = $this->get('/page-qui-nexiste-pas');

        $response->assertStatus(404);
        // Sans SSR, le document initial embarque la page Inertia en JSON :
        // le texte est rendu côté client par Error.vue.
        $this->assertStringContainsString('&quot;component&quot;:&quot;Error&quot;', $response->getContent());
        $this->assertStringContainsString('&quot;status&quot;:404', $response->getContent());
    }

    public function test_api_unknown_url_stays_json(): void
    {
        $response = $this->getJson('/api/inconnu');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'application/json');
    }
}
