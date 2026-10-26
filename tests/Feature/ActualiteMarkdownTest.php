<?php

namespace Tests\Feature;

use App\Models\Actualite;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Actualités rédigées en Markdown : aperçu admin, rendu riche de la page
 * publique, extraits dépouillés de la syntaxe et invalidation des caches
 * (sitemap, flux Atom) à chaque publication.
 */
class ActualiteMarkdownTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENU_MARKDOWN = "## Un titre de section\n\nUn **gras** et un [lien](https://exemple.fr).";

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_aperçu_est_reserve_aux_admins(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/actualites/preview', ['contenu' => self::CONTENU_MARKDOWN])
            ->assertOk()
            ->assertJsonPath('html', "<h2>Un titre de section</h2>\n<p>Un <strong>gras</strong> et un <a href=\"https://exemple.fr\">lien</a>.</p>");
    }

    public function test_aperçu_refuse_les_moderateurs(): void
    {
        $moderateur = User::factory()->create();
        $moderateur->assignRole('moderateur');

        $this->actingAs($moderateur)
            ->postJson('/actualites/preview', ['contenu' => 'x'])
            ->assertForbidden();
    }

    public function test_la_page_publique_rend_le_html_et_un_extrait_propre(): void
    {
        $actualite = Actualite::create([
            'titre' => 'Nouveauté ABC',
            'slug' => 'nouveaute-abc',
            'contenu' => self::CONTENU_MARKDOWN,
            'statut' => 'publie',
            'date_publication' => now()->subDay(),
        ]);

        $this->get('/actualites/'.$actualite->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->component('Actualite')
                ->where('actualite.titre', 'Nouveauté ABC')
                ->where('actualite.contenu_html', "<h2>Un titre de section</h2>\n<p>Un <strong>gras</strong> et un <a href=\"https://exemple.fr\">lien</a>.</p>")
                ->where('actualite.extrait', 'Un titre de section Un gras et un lien.')
        );

        // La liste publique n'expose que l'extrait : jamais le Markdown brut.
        $this->get('/actualites')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Actualites')
                ->where('actualites.0.extrait', 'Un titre de section Un gras et un lien.')
                ->missing('actualites.0.contenu')
        );
    }

    public function test_la_publication_invalide_les_caches_sitemap_et_flux(): void
    {
        Cache::put('abc:sitemap', 'XML PÉRIMÉ', now()->addHours(12));
        Cache::put('abc:feed', 'FLUX PÉRIMÉ', now()->addHour());

        $this->actingAs($this->admin)->post('/actualites', [
            'titre' => 'Une actualité fraîche',
            'contenu' => self::CONTENU_MARKDOWN,
            'statut' => 'publie',
            'date_publication' => now()->toDateString(),
        ])->assertRedirect();

        $this->assertFalse(Cache::has('abc:sitemap'));
        $this->assertFalse(Cache::has('abc:feed'));
    }
}
