<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\ProjectAlias;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Patrimoine écologique INPN sur les fiches projet : résumé (comptages)
 * sur /abc/{slug} et page dédiée /abc/{slug}/patrimoine. Les communes
 * « anomalie » du périmètre sont écartées, comme pour la démographie.
 */
class PatrimoineProjetTest extends TestCase
{
    use RefreshDatabase;

    private const SLUG = 'abc-test-pat';

    private const CODE = '35282'; // commune du périmètre, retenue

    private const CODE_ANOMALIE = '35283'; // commune marquée « à vérifier » : écartée

    private function seedProjet(): void
    {
        $projet = Projet::create([
            'id' => 'abc-test-pat',
            'nom' => 'ABC Test Patrimoine',
            'slug' => self::SLUG,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => self::CODE,
            'libelle_geographique' => 'Rives-du-Couesnon',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => self::CODE_ANOMALIE,
            'libelle_geographique' => 'Commune À Vérifier',
            'anomalie' => true,
        ]);
    }

    private function seedInpn(): void
    {
        DB::table('inpn_imports')->insert([
            ['source' => 'znieff', 'version_source' => 'extraction ZNIEFF INPN 2025', 'nb_entites' => 3, 'importe_le' => now()],
            ['source' => 'natura', 'version_source' => 'Base Natura 2000 — décembre 2024', 'nb_entites' => 1, 'importe_le' => now()],
            ['source' => 'aires', 'version_source' => 'APA-INPN — juillet 2025', 'nb_entites' => 1, 'importe_le' => now()],
        ]);

        DB::table('inpn_znieff')->insert([
            ['id' => 100, 'id_national' => '240009732', 'ty_zone' => 1, 'nom' => 'BOIS DE LOUZAIE', 'surface_ha' => 16.36],
            // Ne touche QUE la commune écartée : ne doit pas apparaître
            ['id' => 300, 'id_national' => '240009735', 'ty_zone' => 2, 'nom' => 'ZONE ANOMALIE', 'surface_ha' => 10],
            // Hors périmètre ABC
            ['id' => 200, 'id_national' => '240009734', 'ty_zone' => 1, 'nom' => 'ZONE HORS ABC', 'surface_ha' => null],
        ]);
        DB::table('inpn_znieff_communes')->insert([
            ['znieff_id' => 100, 'code_commune' => self::CODE],
            ['znieff_id' => 100, 'code_commune' => self::CODE_ANOMALIE],
            ['znieff_id' => 300, 'code_commune' => self::CODE_ANOMALIE],
            ['znieff_id' => 200, 'code_commune' => '99999'],
        ]);
        DB::table('inpn_znieff_especes')->insert([
            ['znieff_id' => 100, 'cd_ref' => 1, 'nom_cite' => 'Espèce A', 'determinante' => true, 'confidentielle' => false],
        ]);

        DB::table('inpn_natura_sites')->insert([
            'sitecode' => 'FR8212001', 'nom' => "DUNES DE L'AUTHIE", 'type_site' => 'ZPS',
        ]);
        DB::table('inpn_natura_communes')->insert([
            ['sitecode' => 'FR8212001', 'code_commune' => self::CODE],
            ['sitecode' => 'FR8212001', 'code_commune' => self::CODE_ANOMALIE],
        ]);
        DB::table('inpn_natura_habitats')->insert([
            ['sitecode' => 'FR8212001', 'cd_ue' => '2110'],
            ['sitecode' => 'FR8212001', 'cd_ue' => '2120'],
        ]);
        DB::table('inpn_natura_especes')->insert([
            ['sitecode' => 'FR8212001', 'code_n2000' => 'A140', 'nom_scientifique' => 'Pluvier argenteus'],
        ]);

        DB::table('inpn_aires_protegees')->insert([
            'id_mnhn' => 'FR3800927', 'territoire' => 'metrop', 'nom' => 'Réserve du Test', 'type_espace' => 'Réserve naturelle nationale',
            'superficie_ha' => 120.5, 'lien_fiche' => 'https://example.test/fiche', 'doc_gestion' => true,
        ]);
        DB::table('inpn_aires_protegees_communes')->insert([
            ['aire_protegee_id' => 'FR3800927', 'code_commune' => self::CODE, 'surface_intersection_ha' => 42.7],
            // Recouvrement sur la commune écartée : pas compté dans la somme
            ['aire_protegee_id' => 'FR3800927', 'code_commune' => self::CODE_ANOMALIE, 'surface_intersection_ha' => 10],
        ]);
    }

    public function test_la_fiche_projet_annonce_le_patrimoine(): void
    {
        $this->seedProjet();
        $this->seedInpn();

        $this->get('/abc/'.self::SLUG)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('patrimoine_resume.present', true)
                ->where('patrimoine_resume.nb_znieff', 1)
                ->where('patrimoine_resume.nb_natura', 1)
                ->where('patrimoine_resume.nb_aires', 1)
        );
    }

    public function test_la_page_dedinee_agrege_le_perimetre(): void
    {
        $this->seedProjet();
        $this->seedInpn();

        $this->get('/abc/'.self::SLUG.'/patrimoine')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('ProjetPatrimoine')
                ->where('projet.nom', 'ABC Test Patrimoine')
                ->where('projet.nb_communes', 2)
                ->where('projet.nb_communes_ecartees', 1)
                ->where('patrimoine.present', true)
                ->where('patrimoine.nb_communes', 1) // commune écartée non comptée
                ->where('patrimoine.versions.znieff', 'extraction ZNIEFF INPN 2025')
                // Une seule ZNIEFF : la zone « anomalie » et la zone hors ABC
                // ne sortent pas, et la commune écartée n'est pas listée.
                ->has('patrimoine.znieff', 1)
                ->where('patrimoine.znieff.0.nom', 'BOIS DE LOUZAIE')
                ->where('patrimoine.znieff.0.nb_especes_determinantes', 1)
                ->has('patrimoine.znieff.0.communes', 1)
                ->where('patrimoine.znieff.0.communes.0.libelle', 'Rives-du-Couesnon')
                ->has('patrimoine.natura', 1)
                ->where('patrimoine.natura.0.nb_habitats', 2)
                ->where('patrimoine.natura.0.nb_especes', 1)
                ->has('patrimoine.natura.0.communes', 1)
                ->has('patrimoine.aires', 1)
                // Somme des recouvrements sur les communes retenues (42,7),
                // le recouvrement de la commune écartée n'est pas compté
                ->where('patrimoine.aires.0.surface_perimetre_ha', 42.7)
                ->has('patrimoine.aires.0.communes', 1)
        );
    }

    public function test_sans_donnees_inpn_resume_et_page_sont_vides(): void
    {
        $this->seedProjet();

        $this->get('/abc/'.self::SLUG)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('patrimoine_resume.present', false)
                ->where('patrimoine_resume.nb_znieff', 0)
        );

        $this->get('/abc/'.self::SLUG.'/patrimoine')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('ProjetPatrimoine')
                ->where('patrimoine.present', false)
                ->where('patrimoine.znieff', [])
                ->where('patrimoine.natura', [])
                ->where('patrimoine.aires', [])
        );
    }

    public function test_slug_inconnu_absent(): void
    {
        $this->get('/abc/inexistant/patrimoine')->assertNotFound();
    }

    public function test_alias_redirige_vers_le_projet_canonique(): void
    {
        $this->seedProjet();
        ProjectAlias::create([
            'project_id' => 'abc-test-pat',
            'alias_project_id' => 'ancien-id',
            'alias_slug' => 'abc-ancien-nom',
            'source' => 'fusion',
        ]);

        $this->get('/abc/abc-ancien-nom/patrimoine')->assertRedirect('/abc/'.self::SLUG.'/patrimoine');
        $this->get('/abc/abc-ancien-nom/patrimoine')->assertStatus(301);
    }

    public function test_sitemap_contient_les_pages_patrimoine_des_projets_concernes(): void
    {
        $this->seedProjet();
        $this->seedInpn();

        Cache::forget('abc:sitemap');
        $xml = (string) $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('/abc/'.self::SLUG.'/patrimoine', $xml);
    }
}
