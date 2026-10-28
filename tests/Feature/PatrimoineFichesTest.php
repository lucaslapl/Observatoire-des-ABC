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
                // Chapeau factuel généré : type, surface, périmètre ABC,
                // département et espèces déterminantes (anti thin content).
                ->where(
                    'znieff.chapeau',
                    'La ZNIEFF de type 1 « BOIS DE LOUZAIE » (identifiant national 240009732) '
                    .'est un secteur d\'intérêt biologique remarquable qui s\'étend sur 16 ha. '
                    .'Elle recouvre 1 commune, dont 1 engagée dans un Atlas de la biodiversité '
                    .'communale : Rives-du-Couesnon (département : Ille-et-Vilaine). '
                    .'L\'inventaire national justifie ce classement par 2 espèces déterminantes.'
                )
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

    public function test_chapeau_unique_par_fiche(): void
    {
        // Deux ZNIEFF partageant la même commune ABC : les chapeaux générés
        // depuis les faits de chaque fiche (type, surface, espèces) diffèrent.
        $a = $this->getJson('/api/v1/patrimoine/znieff/240009732')->json('data.chapeau');
        $b = $this->getJson('/api/v1/patrimoine/znieff/240009733')->json('data.chapeau');

        $this->assertNotSame($a, $b);
        $this->assertStringContainsString('Ille-et-Vilaine', $a);
        $this->assertStringContainsString('16 ha', $a);
        $this->assertStringContainsString('500 ha', $b);
    }

    public function test_chapeau_znieff_groupes_par_effectif_decroissant(): void
    {
        // 3 Flore contre 1 Oiseau : le groupe le plus fourni passe en
        // tête du chapeau, même si le tableau les affiche par ordre
        // alphabétique.
        DB::table('inpn_znieff_especes')->insert([
            ['znieff_id' => 100, 'cd_ref' => 10, 'groupe_taxo' => 'Flore', 'nom_cite' => 'Flore A', 'determinante' => true, 'confidentielle' => false],
            ['znieff_id' => 100, 'cd_ref' => 11, 'groupe_taxo' => 'Flore', 'nom_cite' => 'Flore B', 'determinante' => true, 'confidentielle' => false],
            ['znieff_id' => 100, 'cd_ref' => 12, 'groupe_taxo' => 'Flore', 'nom_cite' => 'Flore C', 'determinante' => true, 'confidentielle' => false],
            ['znieff_id' => 100, 'cd_ref' => 13, 'groupe_taxo' => 'Oiseaux', 'nom_cite' => 'Oiseau A', 'determinante' => true, 'confidentielle' => false],
        ]);

        $chapeau = $this->getJson('/api/v1/patrimoine/znieff/240009732')->json('data.chapeau');

        $this->assertStringContainsString(
            'principalement chez les Flore et les Oiseaux',
            $chapeau
        );
    }

    public function test_chapeau_natura_pluriel_oiseaux(): void
    {
        // Pluriel irrégulier : 2 oiseaux doivent donner « oiseaux », pas
        // « oiseaus » (régression vue sur FR1112012 en production).
        DB::table('inpn_natura_especes')->insert([
            ['sitecode' => 'FR8212001', 'code_n2000' => 'A229', 'nom_scientifique' => 'Alcedo atthis', 'nom_vernaculaire' => "Martin-pêcheur d'Europe", 'annexe_1' => true, 'annexe_ii' => false, 'prioritaire' => false],
        ]);

        $chapeau = $this->getJson('/api/v1/patrimoine/natura/FR8212001')->json('data.chapeau');

        $this->assertStringContainsString('dont 2 oiseaux', $chapeau);
        $this->assertStringNotContainsString('oiseaus', $chapeau);
    }

    public function test_chapeau_natura_site_toutes_oiseaux(): void
    {
        // ZPS où toutes les espèces sont des oiseaux : pas de tournure
        // redondante « dont N oiseaux » sur N espèces.
        DB::table('inpn_natura_sites')->insert([
            ['sitecode' => 'FR2272002', 'nom' => 'ZPS OISEAUX', 'type_site' => 'ZPS'],
        ]);
        DB::table('inpn_natura_communes')->insert([
            ['sitecode' => 'FR2272002', 'code_commune' => self::CODE],
        ]);
        DB::table('inpn_natura_especes')->insert([
            ['sitecode' => 'FR2272002', 'code_n2000' => 'A090', 'nom_scientifique' => 'Ciconia ciconia', 'nom_vernaculaire' => 'Cigogne blanche', 'annexe_1' => true, 'annexe_ii' => false, 'prioritaire' => false],
            ['sitecode' => 'FR2272002', 'code_n2000' => 'A091', 'nom_scientifique' => 'Ciconia nigra', 'nom_vernaculaire' => 'Cigogne noire', 'annexe_1' => true, 'annexe_ii' => false, 'prioritaire' => false],
        ]);

        $chapeau = $this->getJson('/api/v1/patrimoine/natura/FR2272002')->json('data.chapeau');

        $this->assertStringContainsString('2 espèces des directives, toutes oiseaux', $chapeau);
        $this->assertStringNotContainsString('dont 2 oiseaux', $chapeau);
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
                // Chapeau factuel généré : type de site, périmètre ABC,
                // habitat recensé et espèces des directives (anti thin content).
                ->where(
                    'site.chapeau',
                    'Le site Natura 2000 « DUNES DE L\'AUTHIE » (FR8212001) est une zone de '
                    .'protection spéciale (directive « Oiseaux ») du réseau européen Natura 2000. '
                    .'Il recouvre 1 commune, dont 1 engagée dans un Atlas de la biodiversité '
                    .'communale : Rives-du-Couesnon (département : Ille-et-Vilaine). '
                    .'Le formulaire standard de données recense 1 habitat d\'intérêt communautaire : '
                    .'Dunes mobiles embryonnaires. Il mentionne également 2 espèces des directives, '
                    .'dont 1 oiseau et 1 prioritaire.'
                )
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
