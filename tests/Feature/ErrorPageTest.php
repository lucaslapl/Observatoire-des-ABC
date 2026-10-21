<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
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
        // L'URL tentée est passée en prop pour contextualiser le message
        // (les « / » sont échappés dans le JSON embarqué).
        $this->assertStringContainsString('&quot;url&quot;:&quot;http:', $response->getContent());
        $this->assertStringContainsString('page-qui-nexiste-pas&quot;', $response->getContent());
    }

    public function test_api_unknown_url_stays_json(): void
    {
        $response = $this->getJson('/api/inconnu');

        $response->assertStatus(404);
        $response->assertHeader('Content-Type', 'application/json');
    }

    public function test_forbidden_admin_renders_inertia_error_page(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->assignRole('contributeur');

        $response = $this->actingAs($user)->get('/admin');

        $response->assertStatus(403);
        $this->assertStringContainsString('&quot;component&quot;:&quot;Error&quot;', $response->getContent());
        $this->assertStringContainsString('&quot;status&quot;:403', $response->getContent());
    }

    public function test_guest_admin_redirects_to_login(): void
    {
        $response = $this->get('/admin');

        $response->assertRedirect('/login');
    }

    public function test_maintenance_view_is_styled(): void
    {
        $html = view('errors.503')->render();

        $this->assertStringContainsString('Site en cours de maintenance', $html);
        $this->assertStringContainsString('noindex', $html);
    }
}
