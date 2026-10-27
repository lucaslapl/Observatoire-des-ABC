<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Bloc « patrimoine écologique » de la page commune : faits bruts INPN
 * (ZNIEFF, Natura 2000, aires protégées) avec versions de référence,
 * espèces confidentielles jamais exposées, et absence du bloc quand les
 * tables INPN sont vides.
 */
class PatrimoineCommuneTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '35282';

    private function seedCommuneAvecABC(): void
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

    private function seedInpn(): void
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

    public function test_la_page_commune_expose_les_faits_bruts_inpn(): void
    {
        $this->seedCommuneAvecABC();
        $this->seedInpn();

        $this->get('/commune/'.self::CODE)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Commune')
                ->where('patrimoine.present', true)
                ->where('patrimoine.versions.znieff', 'extraction ZNIEFF INPN 2025')
                ->where('patrimoine.versions.natura', 'Base Natura 2000 — décembre 2024')
                ->where('patrimoine.versions.aires', 'APA-INPN — juillet 2025')
                ->has('patrimoine.znieff', 1)
                ->where('patrimoine.znieff.0.nom', 'BOIS DE LOUZAIE')
                ->where('patrimoine.znieff.0.ty_zone', 1)
                ->where('patrimoine.znieff.0.nb_especes_determinantes', 2) // confidentielle exclue
                ->has('patrimoine.natura', 1)
                ->where('patrimoine.natura.0.nom', "DUNES DE L'AUTHIE")
                ->where('patrimoine.natura.0.nb_habitats', 2)
                ->where('patrimoine.natura.0.nb_especes', 1)
                ->has('patrimoine.aires', 1)
                ->where('patrimoine.aires.0.nom', 'Réserve du Test')
                ->where('patrimoine.aires.0.surface_intersection_ha', 42.7)
                ->where('patrimoine.aires.0.doc_gestion', true)
        );
    }

    public function test_sans_donnees_inpn_le_bloc_est_absent(): void
    {
        $this->seedCommuneAvecABC();

        $this->get('/commune/'.self::CODE)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Commune')
                ->where('patrimoine.present', false)
                ->where('patrimoine.znieff', [])
                ->where('patrimoine.natura', [])
                ->where('patrimoine.aires', [])
        );
    }
}
