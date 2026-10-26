<?php

namespace Tests\Feature;

use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ApiPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_page_api_publique_est_rendue(): void
    {
        $this->get('/api')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Api'));
    }

    public function test_les_liens_publics_de_lapi_sont_indexables(): void
    {
        $robots = $this->get('/robots.txt');
        $robots->assertOk();
        $content = $robots->getContent();

        // Les endpoints restent exclus, pas la documentation.
        $this->assertStringContainsString('Disallow: /api/', $content);
        $this->assertStringContainsString('Allow: /api/doc', $content);

        // Sitemap : la page API et la doc y figurent.
        $sitemap = $this->get('/sitemap.xml');
        $sitemap->assertOk();
        $xml = $sitemap->getContent();
        $this->assertStringContainsString('/api</loc>', $xml);
        $this->assertStringContainsString('/api/doc</loc>', $xml);
    }

    public function test_la_documentation_openapi_est_serve(): void
    {
        $doc = $this->get('/api/doc');
        $doc->assertOk();
        $this->assertStringContainsString('text/html', (string) $doc->headers->get('Content-Type'));

        $spec = $this->get('/api/doc/openapi.yaml');
        $spec->assertOk();
        // BinaryFileResponse : le contenu n'est pas sérialisé dans le test,
        // on vérifie le type servi et la présence du fichier généré.
        $this->assertStringContainsString('yaml', (string) $spec->headers->get('Content-Type'));
        $this->assertFileExists(public_path('docs/openapi.yaml'));
        $this->assertStringContainsString('openapi:', (string) file_get_contents(public_path('docs/openapi.yaml')));
    }

    public function test_les_stats_sont_exposees_avec_leurs_compteurs(): void
    {
        Projet::create([
            'id' => 'abc-stats',
            'nom' => 'ABC Stats',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->getJson('/api/v1/stats')
            ->assertOk()
            ->assertJsonPath('data.countProjets', 1)
            ->assertJsonPath('data.licence', 'Etalab-2.0')
            ->assertJsonPath('data.derniere_mise_a_jour', fn ($date) => is_string($date) && str_contains($date, 'T'));
    }
}
