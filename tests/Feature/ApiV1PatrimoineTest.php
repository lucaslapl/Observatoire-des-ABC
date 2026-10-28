<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * API v1 — patrimoine écologique INPN : mêmes faits bruts que le site
 * (ZNIEFF, sites Natura 2000, aires protégées restreints au périmètre
 * des communes ABC), avec attribution INPN/MNHN sur chaque réponse et
 * espèces confidentielles jamais diffusées.
 */
class ApiV1PatrimoineTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '35282';

    protected function seedCommuneAvecABC(): void
    {
        $projet = Projet::create([
            'id' => 'abc-patrimoine',
            'nom' => 'ABC Patrimoine',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => self::CODE,
            'libelle_geographique' => 'Rives-du-Couesnon',
        ]);
    }

    protected function seedInpn(): void
    {
        DB::table('inpn_imports')->insert([
            ['source' => 'znieff', 'version_source' => 'extraction ZNIEFF INPN 2025', 'nb_entites' => 1, 'importe_le' => now()],
            ['source' => 'natura', 'version_source' => 'Base Natura 2000 — décembre 2024', 'nb_entites' => 1, 'importe_le' => now()],
            ['source' => 'aires', 'version_source' => 'APA-INPN — juillet 2025', 'nb_entites' => 1, 'importe_le' => now()],
        ]);

        DB::table('inpn_znieff')->insert([
            'id' => 100, 'id_national' => '240009732', 'ty_zone' => 1, 'nom' => 'BOIS DE LOUZAIE', 'surface_ha' => 16.36,
        ]);
        DB::table('inpn_znieff_communes')->insert([
            ['znieff_id' => 100, 'code_commune' => self::CODE],
        ]);
        DB::table('inpn_znieff_especes')->insert([
            ['znieff_id' => 100, 'cd_ref' => 1, 'nom_cite' => 'Espèce A', 'determinante' => true, 'confidentielle' => false],
            ['znieff_id' => 100, 'cd_ref' => 2, 'nom_cite' => 'Espèce B', 'determinante' => true, 'confidentielle' => false],
            ['znieff_id' => 100, 'cd_ref' => 3, 'nom_cite' => 'Confidentielle', 'determinante' => true, 'confidentielle' => true],
            ['znieff_id' => 100, 'cd_ref' => 4, 'nom_cite' => 'Non déterminante', 'determinante' => false, 'confidentielle' => false],
        ]);

        DB::table('inpn_natura_sites')->insert([
            'sitecode' => 'FR8212001', 'nom' => "DUNES DE L'AUTHIE", 'type_site' => 'ZPS',
        ]);
        DB::table('inpn_natura_communes')->insert([
            'sitecode' => 'FR8212001', 'code_commune' => self::CODE,
        ]);
        DB::table('inpn_natura_habitats')->insert([
            ['sitecode' => 'FR8212001', 'cd_ue' => '2110'],
            ['sitecode' => 'FR8212001', 'cd_ue' => '2120'],
        ]);
        DB::table('inpn_natura_especes')->insert([
            'sitecode' => 'FR8212001', 'code_n2000' => 'A140', 'nom_scientifique' => 'Pluvier argenteus',
        ]);

        DB::table('inpn_aires_protegees')->insert([
            'id_mnhn' => 'FR3800927', 'territoire' => 'metrop', 'nom' => 'Réserve du Test', 'type_espace' => 'Réserve naturelle nationale',
            'superficie_ha' => 120.5, 'lien_fiche' => 'https://example.test/fiche', 'doc_gestion' => true,
        ]);
        DB::table('inpn_aires_protegees_communes')->insert([
            'aire_protegee_id' => 'FR3800927', 'code_commune' => self::CODE, 'surface_intersection_ha' => 42.7,
        ]);
    }

    public function test_patrimoine_de_la_commune(): void
    {
        $this->seedCommuneAvecABC();
        $this->seedInpn();

        $response = $this->getJson('/api/v1/communes/'.self::CODE.'/patrimoine');
        $response->assertOk();

        $this->assertTrue($response->json('data.present'));
        $this->assertSame(1, count($response->json('data.znieff')));
        $this->assertSame('BOIS DE LOUZAIE', $response->json('data.znieff.0.nom'));
        // Espèces déterminantes non confidentielles seulement.
        $this->assertSame(2, $response->json('data.znieff.0.nb_especes_determinantes'));
        $this->assertStringNotContainsString('Confidentielle', $response->getContent());

        $this->assertSame(1, count($response->json('data.natura')));
        $this->assertSame('FR8212001', $response->json('data.natura.0.sitecode'));
        $this->assertSame(2, $response->json('data.natura.0.nb_habitats'));

        $this->assertSame(1, count($response->json('data.aires')));
        $this->assertSame(42.7, $response->json('data.aires.0.surface_intersection_ha'));

        // Versions de référence et attribution MNHN, comme sur le site.
        $this->assertSame('extraction ZNIEFF INPN 2025', $response->json('data.versions.znieff'));
        $this->assertSame("Inventaire national du patrimoine naturel (INPN) — Muséum national d'Histoire naturelle", $response->json('data.attribution.source'));
        $this->assertSame('https://inpn.mnhn.fr', $response->json('data.attribution.url'));
        $this->assertSame('https://inpn.mnhn.fr/legal', $response->json('data.attribution.licences'));
    }

    public function test_patrimoine_de_la_commune_sans_donnees_inpn(): void
    {
        $this->seedCommuneAvecABC();

        $response = $this->getJson('/api/v1/communes/'.self::CODE.'/patrimoine');
        $response->assertOk();

        $this->assertFalse($response->json('data.present'));
        $this->assertSame([], $response->json('data.znieff'));
        // L'attribution reste portée même sans données.
        $this->assertArrayHasKey('attribution', $response->json('data'));
    }

    public function test_patrimoine_commune_inconnue_404(): void
    {
        $this->seedCommuneAvecABC();
        $this->seedInpn();

        $response = $this->getJson('/api/v1/communes/99999/patrimoine');
        $response->assertStatus(404);
        $this->assertSame('Commune introuvable.', $response->json('message'));
    }

    public function test_patrimoine_commune_sans_abc_404(): void
    {
        $this->seedInpn();

        // Le référentiel des communes ne couvre que les communes ABC :
        // pas de fiche, pas de patrimoine exposé (même règle que le site).
        $this->getJson('/api/v1/communes/'.self::CODE.'/patrimoine')
            ->assertStatus(404);
    }

    public function test_listing_znieff(): void
    {
        $this->seedCommuneAvecABC();
        $this->seedInpn();

        $response = $this->getJson('/api/v1/patrimoine/znieff');
        $response->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame(100, $response->json('data.par_page'));
        $this->assertSame('240009732', $response->json('data.items.0.id_national'));
        $this->assertSame(1, $response->json('data.items.0.nb_communes_abc'));
        $this->assertSame('extraction ZNIEFF INPN 2025', $response->json('data.versions.znieff'));
        $this->assertSame('https://inpn.mnhn.fr', $response->json('data.attribution.url'));
    }

    public function test_fiche_znieff(): void
    {
        $this->seedCommuneAvecABC();
        $this->seedInpn();

        $response = $this->getJson('/api/v1/patrimoine/znieff/240009732');
        $response->assertOk();

        $this->assertSame('BOIS DE LOUZAIE', $response->json('data.nom'));
        $this->assertSame(16.36, $response->json('data.surface_ha'));
        $this->assertSame(self::CODE, $response->json('data.communes.0.code'));
        $this->assertSame(1, $response->json('data.nb_communes_total'));

        $groupes = $response->json('data.groupes_especes');
        $this->assertSame(1, count($groupes));
        $this->assertSame(2, $groupes[0]['n']); // hors confidentielles et non déterminantes
        $this->assertStringNotContainsString('Confidentielle', $response->getContent());

        $this->assertSame('extraction ZNIEFF INPN 2025', $response->json('data.versions.znieff'));
        $this->assertArrayHasKey('attribution', $response->json('data'));
    }

    public function test_fiche_znieff_inconnue_404(): void
    {
        $this->seedCommuneAvecABC();

        $response = $this->getJson('/api/v1/patrimoine/znieff/999999999');
        $response->assertStatus(404);
        $this->assertSame('ZNIEFF introuvable ou hors du périmètre des communes ABC.', $response->json('message'));
    }

    public function test_fiche_znieff_hors_perimetre_abc_404(): void
    {
        // ZNIEFF sur une commune sans ABC : pas de fiche publique.
        DB::table('inpn_znieff')->insert([
            'id' => 200, 'id_national' => '240009999', 'ty_zone' => 2, 'nom' => 'ZONE HORS ABC',
        ]);
        DB::table('inpn_znieff_communes')->insert([
            ['znieff_id' => 200, 'code_commune' => '56260'],
        ]);

        $this->getJson('/api/v1/patrimoine/znieff/240009999')->assertStatus(404);
    }

    public function test_listing_natura(): void
    {
        $this->seedCommuneAvecABC();
        $this->seedInpn();

        $response = $this->getJson('/api/v1/patrimoine/natura');
        $response->assertOk();

        $this->assertSame(1, $response->json('data.total'));
        $this->assertSame('FR8212001', $response->json('data.items.0.sitecode'));
        $this->assertSame('ZPS', $response->json('data.items.0.type_site'));
        $this->assertSame(1, $response->json('data.items.0.nb_communes_abc'));
        $this->assertSame('Base Natura 2000 — décembre 2024', $response->json('data.versions.natura'));
        $this->assertSame('https://inpn.mnhn.fr/legal', $response->json('data.attribution.licences'));
    }

    public function test_fiche_natura(): void
    {
        $this->seedCommuneAvecABC();
        $this->seedInpn();

        $response = $this->getJson('/api/v1/patrimoine/natura/FR8212001');
        $response->assertOk();

        $this->assertSame("DUNES DE L'AUTHIE", $response->json('data.nom'));
        $this->assertSame(2, count($response->json('data.habitats')));
        $this->assertSame(1, count($response->json('data.especes')));
        $this->assertSame(self::CODE, $response->json('data.communes.0.code'));
        $this->assertSame('Base Natura 2000 — décembre 2024', $response->json('data.versions.natura'));
        $this->assertArrayHasKey('attribution', $response->json('data'));
    }

    public function test_fiche_natura_inconnue_404(): void
    {
        $this->seedCommuneAvecABC();

        $response = $this->getJson('/api/v1/patrimoine/natura/FR9999999');
        $response->assertStatus(404);
        $this->assertSame('Site Natura 2000 introuvable ou hors du périmètre des communes ABC.', $response->json('message'));
    }

    public function test_route_invalide_rejetee(): void
    {
        // Les contraintes de route protègent les identifiants malformés.
        $this->getJson('/api/v1/patrimoine/znieff/abc')->assertStatus(404);
        $this->getJson('/api/v1/patrimoine/natura/xyz')->assertStatus(404);
    }
}
