<?php

namespace Tests\Feature;

use App\Models\Actualite;
use App\Models\Commune;
use App\Models\Projet;
use App\Models\Verification;
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
                ->where('projet.pnrs', [])
        );
    }

    public function test_projet_page_exposes_unique_pnrs(): void
    {
        $this->seedData();
        $projet = Projet::firstOrFail();

        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56002',
            'libelle_geographique' => 'Auray',
            'libelle_pnr' => 'PNR du Golfe du Morbihan',
            'anomalie' => false,
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56003',
            'libelle_geographique' => 'Locmariaquer',
            'libelle_pnr' => ' PNR du Golfe du Morbihan ',
            'anomalie' => false,
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56004',
            'libelle_geographique' => 'Sainte-Anne',
            'libelle_pnr' => '   ',
            'anomalie' => false,
        ]);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.pnrs', ['PNR du Golfe du Morbihan'])
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

    public function test_departement_uppercase_code_redirects_301_to_lowercase(): void
    {
        $projet = Projet::create([
            'id' => 'abc-corse',
            'nom' => 'ABC de Corse du Sud',
            'statut' => 'en_cours',
            'source' => 'data_gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '2A004',
            'libelle_geographique' => 'Ajaccio',
            'departement' => '2A',
            'libelle_departement' => 'Corse-du-Sud',
            'region' => 'Corse',
            'anomalie' => false,
        ]);

        $this->get('/departement/2A')
            ->assertStatus(301)
            ->assertRedirect('/departement/2a');
        $this->get('/departement/2a')->assertOk();
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
        $this->get('/a-propos')
            ->assertOk()
            ->assertSee('chiffres')
            ->assertSee('projets')
            ->assertSee('communes')
            ->assertSee('verifies');
        $this->get('/mentions-legales')->assertOk();
        $this->get('/confidentialite')->assertOk();

        $this->get('/methodologie')
            ->assertOk()
            ->assertSee('chiffres')
            ->assertSee('doublons_fusionnes')
            ->assertSee('en_verification');
    }

    public function test_robots_txt_points_to_sitemap(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /api/')
            ->assertSee('Sitemap:')
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_sitemap_is_cacheable(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=3600, public');
    }

    public function test_feed_lists_published_actualites_only(): void
    {
        $publiee = Actualite::create([
            'titre' => 'Nouveauté ABC',
            'slug' => 'nouveaute-abc',
            'contenu' => 'Contenu de la publication.',
            'statut' => 'publie',
            'date_publication' => now()->subDay(),
        ]);
        Actualite::create([
            'titre' => 'Brouillon',
            'slug' => 'brouillon',
            'contenu' => 'Non publié.',
            'statut' => 'brouillon',
        ]);

        $response = $this->get('/feed.xml');

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'max-age=3600, public')
            ->assertSee('<feed xmlns="http://www.w3.org/2005/Atom">', false)
            ->assertSee('/actualites/'.$publiee->slug, false)
            ->assertSee('<title>Nouveauté ABC</title>', false)
            ->assertDontSee('brouillon');
    }

    public function test_sitemap_contains_landing_urls(): void
    {
        $this->seedData();
        $projet = Projet::firstOrFail();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/carte')
            ->assertSee('/abc/'.$projet->slug)
            ->assertSee('/commune/56001')
            ->assertSee('/departement/56')
            ->assertSee('/region/bretagne')
            ->assertSee('/actualites')
            ->assertSee('/contact')
            ->assertSee('/a-propos')
            ->assertSee('/methodologie')
            ->assertSee('/mentions-legales')
            ->assertSee('/confidentialite');
    }

    public function test_saving_hook_backfills_null_slug_on_update(): void
    {
        $projet = Projet::create([
            'id' => 'abc-backfill',
            'nom' => 'ABC de la ville test',
            'statut' => 'a_venir',
            'source' => 'data.gouv',
        ]);

        // Simule une ligne héritée sans slug (import SQL brutal, migration jamais appliquée).
        Projet::query()->whereKey($projet->id)->update(['slug' => null]);
        $this->assertNull($projet->refresh()->slug);

        // Le moindre save() (création OU mise à jour) doit rétro-remplir le slug.
        $projet = Projet::findOrFail($projet->id);
        $projet->statut = 'en_cours';
        $projet->save();

        $this->assertNotEmpty($projet->slug);
        $this->get('/abc/'.$projet->slug)->assertOk();
    }

    public function test_departement_page_never_links_to_null_slug(): void
    {
        $this->seedData();
        $projet = Projet::firstOrFail();

        // Simule un projet dont le slug est resté vide en base.
        Projet::query()->whereKey($projet->id)->update(['slug' => null]);

        $this->get('/departement/56')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Departement')
                ->where('departement.projets.0.slug', null)
                ->where('departement.projets.0.nom', $projet->nom)
        );
    }

    public function test_departement_page_includes_projects_with_missing_departement_column(): void
    {
        $this->seedData();
        Projet::create([
            'id' => 'atlas-vitre-2025',
            'nom' => 'ABC de Vitré 2025',
            'statut' => 'a_venir',
            'source' => 'data.gouv',
            'annee_debut' => 2025,
            'structure_porteuse' => 'Ville de Vitré',
        ]);
        // Même commune, mais la ligne n'a pas de colonne `departement` renseignée.
        Commune::create([
            'projet_id' => 'atlas-vitre-2025',
            'code_geographique' => '56002',
            'libelle_geographique' => 'Vannes',
            'departement' => null,
            'region' => 'Bretagne',
        ]);

        $this->get('/departement/56')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Departement')
                ->has('departement.projets', 2)
                ->where('departement.projets.0.nom', 'ABC de Vitré 2025')
        );
    }

    public function test_departement_page_groups_projects_by_commune_most_recent_first(): void
    {
        $this->seedData();
        // Vitré a fait un ABC en 2010, puis un nouveau (Fonds vert) qui
        // débutera en 2026 : les deux lignes se suivent, la plus récente
        // d'abord — une commune qui refait un ABC se lit d'un coup d'œil.
        Projet::create([
            'id' => 'abc-vitre-2010',
            'nom' => 'ABC de Vitré',
            'statut' => 'termine',
            'source' => 'data.gouv',
            'annee_debut' => 2010,
        ]);
        Projet::create([
            'id' => 'atlas-vitre-2026',
            'nom' => 'Atlas de la Biodiversité Communale',
            'statut' => 'a_venir',
            'source' => 'fondsvert-p113-2025',
            'annee_debut' => 2026,
        ]);
        Commune::create([
            'projet_id' => 'abc-vitre-2010',
            'code_geographique' => '56010',
            'libelle_geographique' => 'Vitré',
            'departement' => '56',
            'anomalie' => false,
        ]);
        Commune::create([
            'projet_id' => 'atlas-vitre-2026',
            'code_geographique' => '56010',
            'libelle_geographique' => 'Vitré',
            // Sans colonne département : rattrapé par le préfixe INSEE 56.
            'departement' => null,
            'anomalie' => false,
        ]);

        $this->get('/departement/56')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Departement')
                ->has('departement.projets', 3)
                // Groupes de communes par ordre alphabétique…
                ->where('departement.projets.0.commune_principale', 'Vannes')
                // …et, au sein d'un groupe, l'ABC le plus récent en premier.
                ->where('departement.projets.1.commune_principale', 'Vitré')
                ->where('departement.projets.1.annee_debut', 2026)
                ->where('departement.projets.2.commune_principale', 'Vitré')
                ->where('departement.projets.2.annee_debut', 2010)
                ->has('departement.communes', 2)
        );
    }

    public function test_listing_pages_show_the_verified_status_over_the_computed_one(): void
    {
        $this->seedData();
        // Le registre dit « va débuter », un vérificateur a constaté « en
        // cours » : le badge des pages de liste et de la fiche doit montrer
        // le verdict, comme le fait déjà la carte (statut_affichage).
        Projet::query()->whereKey('abc-morbihan')->update(['statut' => 'a_venir']);
        Verification::create([
            'projet_id' => 'abc-morbihan',
            'etat' => 'confirme_en_cours',
        ]);

        $this->get('/departement/56')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Departement')
                ->where('departement.projets.0.statut', 'en_cours')
                ->where('departement.projets.0.statut_label', 'En cours')
        );

        $this->get('/commune/56001')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Commune')
                ->where('commune.projets.0.statut', 'en_cours')
                ->where('commune.projets.0.statut_label', 'En cours')
        );

        $projet = Projet::firstOrFail();
        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.statut', 'en_cours')
                ->where('projet.statut_label', 'En cours')
        );
    }

    public function test_carte_page_returns_map_payload(): void
    {
        $this->seedData();

        $this->get('/carte')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Carte')
                ->has('meta')
        );
    }

    public function test_commune_page_lists_projects_with_anomalous_association(): void
    {
        $this->seedData();
        Projet::create([
            'id' => 'abc-anomalie',
            'nom' => 'ABC potentiellement mal rattaché',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2023,
        ]);
        Commune::create([
            'projet_id' => 'abc-anomalie',
            'code_geographique' => '56001',
            'libelle_geographique' => 'Vannes',
            'departement' => '56',
            'region' => 'Bretagne',
            'anomalie' => true,
        ]);

        // La carte affiche les associations anormales : la page commune doit les lister aussi.
        $this->get('/commune/56001')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Commune')
                ->has('commune.projets', 2)
                ->where('commune.projets.0.projet_id', 'abc-anomalie')
                ->where('commune.projets.0.anomalie', true)
        );
    }
}
