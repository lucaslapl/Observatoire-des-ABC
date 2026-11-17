<?php

namespace Tests\Feature;

use App\Models\Actualite;
use App\Models\Commune;
use App\Models\Projet;
use App\Models\User;
use App\Services\SitemapService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sitemap (/sitemap.xml) : couverture de toutes les sections (audit SEO du
 * 2026-11-17, C1 — sitemap de production figé à 179 URLs) et invalidation
 * durable via la clé de cache versionnée + abc:sitemap:flush.
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'abc-test-sitemap';

    private const CODE = '56001';

    protected function seedData(): void
    {
        $projet = Projet::create([
            'id' => 'abc-test-sitemap',
            'nom' => 'ABC de Test Sitemap',
            'slug' => self::SLUG,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => self::CODE,
            'libelle_geographique' => 'Vannes',
            'departement' => '56',
            'libelle_departement' => 'Morbihan',
            'region' => 'Bretagne',
            'anomalie' => false,
        ]);

        // Patrimoine INPN touchant la commune du périmètre : alimente les
        // sections znieff, natura et abc/patrimoine.
        DB::table('inpn_znieff')->insert([
            'id' => 100, 'id_national' => '240009732', 'ty_zone' => 1, 'nom' => 'BOIS DE LOUZAIE', 'surface_ha' => 16.36,
        ]);
        DB::table('inpn_znieff_communes')->insert([
            ['znieff_id' => 100, 'code_commune' => self::CODE],
        ]);
        DB::table('inpn_natura_sites')->insert([
            'sitecode' => 'FR8212001', 'nom' => "DUNES DE L'AUTHIE", 'type_site' => 'ZPS',
        ]);
        DB::table('inpn_natura_communes')->insert([
            ['sitecode' => 'FR8212001', 'code_commune' => self::CODE],
        ]);

        Actualite::create([
            'titre' => 'Actualité sitemap',
            'slug' => 'actualite-sitemap',
            'contenu' => 'Contenu.',
            'statut' => 'publie',
            'date_publication' => now()->subDay(),
        ]);
    }

    public function test_sitemap_contient_au_moins_une_url_par_section(): void
    {
        $this->seedData();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            // Pages statiques.
            ->assertSee('/carte</loc>', false)
            ->assertSee('/abc</loc>', false)
            // Actualités publiées.
            ->assertSee('/actualites/actualite-sitemap</loc>', false)
            // Fiches projets.
            ->assertSee('/abc/'.self::SLUG.'</loc>', false)
            // Communes.
            ->assertSee('/commune/'.self::CODE.'</loc>', false)
            // Départements.
            ->assertSee('/departement/56</loc>', false)
            // Régions.
            ->assertSee('/region/bretagne</loc>', false)
            // Patrimoine INPN : hubs, fiches ZNIEFF, fiches Natura.
            ->assertSee('/patrimoine</loc>', false)
            ->assertSee('/patrimoine/znieff</loc>', false)
            ->assertSee('/patrimoine/znieff/240009732</loc>', false)
            ->assertSee('/patrimoine/natura</loc>', false)
            ->assertSee('/patrimoine/natura/FR8212001</loc>', false)
            // Pages « patrimoine du périmètre » des fiches ABC.
            ->assertSee('/abc/'.self::SLUG.'/patrimoine</loc>', false);
    }

    /**
     * Une entrée de cache héritée d'une ancienne version du générateur
     * (l'ancienne clé non versionnée) ne doit jamais être servie : la clé
     * courante est indépendante.
     */
    public function test_entree_de_cache_heritee_est_ignoree(): void
    {
        $this->seedData();

        Cache::put('abc:sitemap', '<?xml?><urlset>HERITAGE FIGE</urlset>', now()->addYear());

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/abc/'.self::SLUG, false)
            ->assertDontSee('HERITAGE FIGE');

        // L'entrée héritée reste en place (aucune purge implicite) mais
        // n'est plus jamais lue.
        $this->assertNotNull(Cache::get('abc:sitemap'));
    }

    public function test_flush_commande_oublie_les_cles_et_rechauffe_le_cache(): void
    {
        $this->seedData();

        Cache::put('abc:sitemap', 'entree-heritage', now()->addYear());

        $this->artisan('abc:sitemap:flush')->assertExitCode(0);

        $this->assertNull(Cache::get('abc:sitemap'), "l'entrée héritée doit être oubliée");
        $this->assertTrue(Cache::has(SitemapService::CACHE_KEY), 'le sitemap frais doit être réchauffé');

        // Le sitemap servi vient du cache réchauffé par la commande.
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('/abc/'.self::SLUG, false)
            ->assertDontSee('entree-heritage');
    }

    public function test_flush_regenere_toutes_les_sections_avec_leurs_comptages(): void
    {
        $this->seedData();

        $r = app(SitemapService::class)->flush();

        $expected = [
            'statiques' => 13,
            'actualites' => 1,
            'abc' => 1,
            'communes' => 1,
            'departements' => 1,
            // Toutes les régions du référentiel sont pré-inscrites, même
            // sans commune ABC : 18 libellés dans config/regions.php.
            'regions' => 18,
            'patrimoine' => 3,
            'znieff' => 1,
            'natura' => 1,
            'abc_patrimoine' => 1,
        ];

        $this->assertSame($expected, $r['sections']);
        $this->assertSame(array_sum($expected), $r['total']);
    }

    public function test_route_admin_flush_requiert_un_admin(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->postJson('/api/admin/sitemap/flush')->assertUnauthorized();

        $modo = User::factory()->create();
        $modo->assignRole('moderateur');
        $this->actingAs($modo)->postJson('/api/admin/sitemap/flush')->assertForbidden();
    }

    public function test_route_admin_flush_regenere_le_sitemap(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $this->seedData();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->postJson('/api/admin/sitemap/flush')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('total', 41)
            ->assertJsonPath('sections.abc', 1)
            ->assertJsonPath('sections.znieff', 1)
            ->assertJsonPath('sections.abc_patrimoine', 1);

        $this->assertNull(Cache::get('abc:sitemap'));
        $this->assertTrue(Cache::has(SitemapService::CACHE_KEY));
    }
}
