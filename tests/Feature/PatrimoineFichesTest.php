<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Fiches publiques du patrimoine écologique : ZNIEFF et Natura 2000
 * éligibles (touchant une commune ABC) seulement — 404 sinon.
 * Confidentialité, assainissement du HTML INPN, sitemap et listings.
 */
class PatrimoineFichesTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = '35282';

    private string $publicHermetique;

    protected function setUp(): void
    {
        parent::setUp();

        // Dossier DOCOB éphémère : la détection du PDF local doit être
        // hermétique (la machine de dev a les 1 759 PDF réels, la CI non).
        $this->publicHermetique = sys_get_temp_dir().'/public-docob-'.uniqid();
        mkdir($this->publicHermetique, 0777, true);
        file_put_contents($this->publicHermetique.'/FR8212001.pdf', 'stub');
        config(['abc.inpn.docob_dir' => $this->publicHermetique]);

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

        // ZNIEFF type 2 (parente), type 1 éligible, et zone hors périmètre ABC.
        DB::table('inpn_znieff')->insert([
            ['id' => 100, 'id_national' => '240009732', 'ty_zone' => 1, 'nom' => 'BOIS DE LOUZAIE', 'surface_ha' => 16.36, 'id_parent' => 150, 'texte_interet' => '<p onclick="evil()">Boisement <b>remarquable</b></p>'],
            ['id' => 150, 'id_national' => '240009733', 'ty_zone' => 2, 'nom' => 'ENSEMBLE TEST', 'surface_ha' => 500, 'id_parent' => null, 'texte_interet' => null],
            ['id' => 200, 'id_national' => '240009734', 'ty_zone' => 1, 'nom' => 'ZONE HORS ABC', 'surface_ha' => null, 'id_parent' => null, 'texte_interet' => null],
        ]);
        DB::table('inpn_znieff_communes')->insert([
            ['znieff_id' => 100, 'code_commune' => self::CODE],
            ['znieff_id' => 150, 'code_commune' => self::CODE],
            ['znieff_id' => 200, 'code_commune' => '99999'],
        ]);
        DB::table('inpn_znieff_especes')->insert([
            ['znieff_id' => 100, 'cd_ref' => 1, 'nom_cite' => '<i>Espèce déterminante A</i>', 'determinante' => true, 'confidentielle' => false],
            ['znieff_id' => 100, 'cd_ref' => 2, 'nom_cite' => '<i>Espèce déterminante B</i>', 'determinante' => true, 'confidentielle' => false],
            ['znieff_id' => 100, 'cd_ref' => 3, 'nom_cite' => 'Espèce confidentielle', 'determinante' => true, 'confidentielle' => true],
            ['znieff_id' => 100, 'cd_ref' => 4, 'nom_cite' => 'Espèce à enjeux', 'determinante' => false, 'confidentielle' => false],
        ]);

        DB::table('inpn_natura_sites')->insert([
            ['sitecode' => 'FR8212001', 'nom' => "DUNES DE L'AUTHIE", 'type_site' => 'ZPS'],
            ['sitecode' => 'FR1100795', 'nom' => 'SITE HORS ABC', 'type_site' => 'SIC'],
        ]);
        DB::table('inpn_natura_communes')->insert([
            ['sitecode' => 'FR8212001', 'code_commune' => self::CODE],
        ]);
        DB::table('inpn_natura_habitats')->insert([
            ['sitecode' => 'FR8212001', 'cd_ue' => '2110', 'nom' => 'Dunes mobiles embryonnaires', 'prioritaire' => false, 'cd_hab' => '2682', 'representativite' => 'B', 'conservation' => 'B', 'evaluation_globale' => 'C'],
        ]);
        DB::table('inpn_natura_especes')->insert([
            // A### = oiseau (directive Oiseaux, annexe I)
            ['sitecode' => 'FR8212001', 'code_n2000' => 'A140', 'nom_scientifique' => 'Pluvier argenteus', 'nom_vernaculaire' => 'Pluvier doré', 'annexe_1' => true, 'annexe_ii' => false, 'prioritaire' => false],
            // code numérique = espèce Habitats (annexe II, prioritaire)
            ['sitecode' => 'FR8212001', 'code_n2000' => '1355', 'nom_scientifique' => 'Lutra lutra', 'nom_vernaculaire' => "Loutre d'Europe", 'annexe_1' => false, 'annexe_ii' => true, 'prioritaire' => true],
        ]);
        DB::table('inpn_natura_docob')->insert([
            ['sitecode' => 'FR8212001', 'titre' => 'DOCOB 2012', 'lien' => 'http://natura2000.mnhn.fr/uploads/doc/PRODBIOTOP/1382_DOCOB.pdf'],
        ]);

        DB::table('inpn_imports')->insert([
            ['source' => 'znieff', 'version_source' => 'extraction ZNIEFF INPN 2025', 'nb_entites' => 3, 'importe_le' => now()],
            ['source' => 'natura', 'version_source' => 'Base Natura 2000 — décembre 2024', 'nb_entites' => 1, 'importe_le' => now()],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->publicHermetique.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->publicHermetique);
        parent::tearDown();
    }

    public function test_fiche_znieff_eligible(): void
    {
        $this->get('/patrimoine/znieff/240009732')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('PatrimoineZnieff')
                ->where('znieff.nom', 'BOIS DE LOUZAIE')
                ->where('znieff.ty_zone', 1)
                ->where('znieff.parent.nom', 'ENSEMBLE TEST')
                ->where('znieff.communes.0.libelle', 'Rives-du-Couesnon')
                // HTML INPN assaini : attribut retiré, mise en forme conservée
                ->where('znieff.texte_interet', '<p>Boisement <b>remarquable</b></p>')
                // 2 déterminantes non confidentielles, groupées ; l'espèce à
                // enjeux et la confidentielle ne sortent pas
                // 2 déterminantes non confidentielles, groupées ; l'espèce à
                // enjeux et la confidentielle ne sortent pas
                ->has('znieff.groupes_especes', 1)
                ->where('znieff.groupes_especes.0.n', 2)
                // <i>Espèce déterminante A</i> -> texte pur, sans balise
                ->where('znieff.groupes_especes.0.noms.0', 'Espèce déterminante A')
                // date de référence de la base, affichée avec l'attribution
                ->where('znieff.versions.znieff', 'extraction ZNIEFF INPN 2025')
        );
    }

    public function test_fiche_znieff_hors_perimetre_abcs_absente(): void
    {
        $this->get('/patrimoine/znieff/240009734')->assertNotFound();
    }

    public function test_fiche_znieff_inconnue_absente(): void
    {
        $this->get('/patrimoine/znieff/999999999')->assertNotFound();
    }

    public function test_fiche_natura_eligible(): void
    {
        $this->get('/patrimoine/natura/FR8212001')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('PatrimoineNatura')
                ->where('site.nom', "DUNES DE L'AUTHIE")
                ->where('site.type_site', 'ZPS')
                ->where('site.communes.0.libelle', 'Rives-du-Couesnon')
                ->has('site.habitats', 1)
                ->where('site.habitats.0.nom', 'Dunes mobiles embryonnaires')
                ->where('site.habitats.0.conservation', 'B')
                ->where('site.habitats.0.evaluation_globale', 'C')
                ->has('site.especes', 2)
                ->where('site.especes.0.nom_scientifique', 'Lutra lutra')
                ->where('site.especes.0.annexe_ii', true)
                ->where('site.especes.0.prioritaire', true)
                ->where('site.especes.1.nom_scientifique', 'Pluvier argenteus')
                ->where('site.especes.1.annexe_1', true)
                ->where('site.especes.1.annexe_ii', false)
                ->where('site.docob.0.titre', 'DOCOB 2012')
                // DOCOB local détecté via le public_path éphémère du test
                ->where('site.pdf_local', '/docob/FR8212001.pdf')
                // date de référence de la base, affichée avec l'attribution
                ->where('site.versions.natura', 'Base Natura 2000 — décembre 2024')
        );
    }

    public function test_fiche_natura_hors_perimetre_abcs_absente(): void
    {
        $this->get('/patrimoine/natura/FR1100795')->assertNotFound();
    }

    public function test_hub_et_listings(): void
    {
        $this->get('/patrimoine')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Patrimoine')
                ->where('stats.nb_znieff', 2)
                ->where('stats.nb_natura', 1)
        );

        $this->get('/patrimoine/znieff')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('PatrimoineZnieffListe')
                ->where('listing.total', 2)
                ->has('listing.items', 2)
        );

        $this->get('/patrimoine/natura')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('PatrimoineNaturaListe')
                ->where('listing.total', 1)
                ->has('listing.items', 1)
        );
    }

    public function test_sitemap_contient_les_fiches_eligibles_seulement(): void
    {
        Cache::forget('abc:sitemap');
        $xml = (string) $this->get('/sitemap.xml')->getContent();

        $this->assertStringContainsString('/patrimoine/znieff/240009732', $xml);
        $this->assertStringContainsString('/patrimoine/znieff/240009733', $xml);
        $this->assertStringContainsString('/patrimoine/natura/FR8212001', $xml);
        $this->assertStringNotContainsString('/patrimoine/znieff/240009734', $xml);
        $this->assertStringNotContainsString('/patrimoine/natura/FR1100795', $xml);
    }
}
