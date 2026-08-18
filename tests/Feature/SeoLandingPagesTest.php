<?php

namespace Tests\Feature;

use App\Models\Actualite;
use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SeoLandingPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function seedData(): void
    {
        $projet = Projet::create([
            'id' => 'abc-morbihan',
            'nom' => "ABC de la commune d'exemple",
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2024,
            'structure_porteuse' => 'Communauté de communes',
        ]);

        Commune::create([
            'projet_id' => 'abc-morbihan',
            'code_geographique' => '56001',
            'libelle_geographique' => 'Vannes',
            'departement' => '56',
            'libelle_departement' => 'Morbihan',
            'region' => 'Bretagne',
            'anomalie' => false,
        ]);
    }

    public function test_home_expose_region_and_department_index(): void
    {
        $this->seedData();

        $this->get('/')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Map', true)
                ->has('index.regions')
                ->has('index.departements')
        );
    }

    public function test_projet_page_returns_expected_payload(): void
    {
        $this->seedData();
        $projet = Projet::firstOrFail();

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.slug', $projet->slug)
                ->where('projet.statut_label', 'En cours')
                ->has('projet.communes', 1)
        );
    }

    public function test_projet_without_slug_returns_404(): void
    {
        $this->seedData();
        $this->get('/abc/n-existe-pas')->assertNotFound();
    }

    public function test_commune_page_returns_projects(): void
    {
        $this->seedData();

        $this->get('/commune/56001')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Commune')
                ->where('commune.libelle', 'Vannes')
                ->where('commune.region.label', 'Bretagne')
                ->has('commune.projets', 1)
        );
    }

    public function test_commune_unknown_returns_404(): void
    {
        $this->get('/commune/99999')->assertNotFound();
    }

    public function test_departement_page_returns_projects(): void
    {
        $this->seedData();

        $this->get('/departement/56')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Departement')
                ->where('departement.label', 'Morbihan')
                ->has('departement.projets', 1)
        );
    }

    public function test_region_page_returns_departments(): void
    {
        $this->seedData();

        $this->get('/region/bretagne')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Region')
                ->where('region.label', 'Bretagne')
                ->has('region.departements', 1)
        );
    }

    public function test_region_unknown_returns_404(): void
    {
        $this->get('/region/inconnue')->assertNotFound();
    }

    public function test_actualite_detail_published(): void
    {
        $actualite = Actualite::create([
            'titre' => 'Nouveauté ABC',
            'slug' => 'nouveaute-abc',
            'contenu' => 'Contenu de la publication.',
            'statut' => 'publie',
            'date_publication' => now()->subDay(),
        ]);

        $this->get('/actualites')->assertOk();
        $this->get('/actualites/'.$actualite->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Actualite')->where('actualite.titre', 'Nouveauté ABC')
        );
    }

    public function test_actualite_unpublished_returns_404(): void
    {
        Actualite::create([
            'titre' => 'Brouillon',
            'slug' => 'brouillon',
            'contenu' => '...',
            'statut' => 'brouillon',
        ]);

        $this->get('/actualites/brouillon')->assertNotFound();
    }

    public function test_about_pages_rendered(): void
    {
        $this->get('/mentions-legales')->assertOk();
        $this->get('/confidentialite')->assertOk();
    }

    public function test_robots_txt_points_to_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /api/')
            ->assertSee('Sitemap:');
    }

    public function test_sitemap_contains_landing_urls(): void
    {
        $this->seedData();
        $projet = Projet::firstOrFail();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/abc/'.$projet->slug)
            ->assertSee('/commune/56001')
            ->assertSee('/departement/56')
            ->assertSee('/region/bretagne')
            ->assertSee('/actualites');
    }
}
