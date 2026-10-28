<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use App\Models\Verification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Liste « tous les ABC » (/abc) : filtres, tris, statut vérifié prioritaire,
 * agrégats du périmètre (population, superficie, densité, patrimoine INPN)
 * et pagination. Les communes « anomalie » sont écartées des comptages.
 */
class ProjetsListeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $communes
     */
    private function projet(string $id, string $nom, array $attributs = [], array $communes = []): Projet
    {
        $projet = Projet::create(array_merge([
            'id' => $id,
            'nom' => $nom,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ], $attributs));

        foreach ($communes as $c) {
            Commune::create(['projet_id' => $projet->id] + $c);
        }

        return $projet;
    }

    private function patrimoine(string $codeCommune): void
    {
        DB::table('inpn_znieff')->insert([
            ['id' => 100, 'id_national' => '240009732', 'ty_zone' => 1, 'nom' => 'BOIS DE LOUZAIE'],
            ['id' => 200, 'id_national' => '240009734', 'ty_zone' => 2, 'nom' => 'ZONE AUTRE'],
        ]);
        DB::table('inpn_znieff_communes')->insert([
            ['znieff_id' => 100, 'code_commune' => $codeCommune],
            ['znieff_id' => 200, 'code_commune' => $codeCommune],
        ]);
        DB::table('inpn_znieff_especes')->insert([
            ['znieff_id' => 100, 'cd_ref' => 60789, 'nom_cite' => 'Loutre', 'determinante' => true, 'confidentielle' => false],
        ]);

        DB::table('inpn_natura_sites')->insert(['sitecode' => 'FR8212001', 'nom' => 'DUNES', 'type_site' => 'ZPS']);
        DB::table('inpn_natura_communes')->insert(['sitecode' => 'FR8212001', 'code_commune' => $codeCommune]);
        DB::table('inpn_natura_habitats')->insert(['sitecode' => 'FR8212001', 'cd_ue' => '2110']);

        DB::table('inpn_aires_protegees')->insert(['id_mnhn' => 'FR3800927', 'territoire' => 'metrop', 'nom' => 'RNN EXEMPLE', 'type_espace' => 'RNN']);
        DB::table('inpn_aires_protegees')->insert(['id_mnhn' => 'FR3800928', 'territoire' => 'metrop', 'nom' => 'APB EXEMPLE', 'type_espace' => 'APB']);
        DB::table('inpn_aires_protegees_communes')->insert([
            ['aire_protegee_id' => 'FR3800927', 'code_commune' => $codeCommune],
            ['aire_protegee_id' => 'FR3800928', 'code_commune' => $codeCommune],
        ]);
    }

    public function test_la_liste_affiche_les_projets_tries_par_nom(): void
    {
        $this->projet('abc-b', 'ABC BBB');
        $this->projet('abc-a', 'ABC AAA');

        $this->get('/abc')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('ProjetsListe')
                ->where('liste.total', 2)
                ->where('liste.items.0.nom', 'ABC AAA')
                ->where('liste.items.1.nom', 'ABC BBB')
                ->where('liste.query.tri', 'nom')
                ->has('liste.filtres.statuts')
                ->has('liste.filtres.regions')
        );
    }

    public function test_le_statut_verifie_prime_sur_le_statut_calcule(): void
    {
        $projet = $this->projet('abc-verif', 'ABC Verifie');
        Verification::create(['projet_id' => $projet->id, 'etat' => 'confirme_termine']);

        $this->get('/abc')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->where('liste.items.0.statut', 'termine')
                ->where('liste.items.0.statut_verifie', true)
        );

        // Le filtre statut=termine le retrouve, alors que projets.statut = en_cours.
        $this->get('/abc?statut=termine')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-verif')
        );
    }

    public function test_tri_par_population_descroissante(): void
    {
        $this->projet('abc-petit', 'ABC Petit', [], [
            ['code_geographique' => '11111', 'libelle_geographique' => 'Petite', 'population' => 500, 'surface_km2' => 10.0],
        ]);
        $this->projet('abc-grand', 'ABC Grand', [], [
            ['code_geographique' => '22222', 'libelle_geographique' => 'Grande', 'population' => 50000, 'surface_km2' => 50.0],
        ]);

        $this->get('/abc?tri=population&sens=desc')->assertOk()->assertInertia(
            fn (Assert $page) => $page
                ->where('liste.items.0.id', 'abc-grand')
                ->where('liste.items.0.population', 50000)
                ->where('liste.items.0.superficie_km2', 50)
                ->where('liste.items.0.densite', 1000)
                ->where('liste.items.1.id', 'abc-petit')
        );
    }

    public function test_filtres_population_et_densite(): void
    {
        $this->projet('abc-urbain', 'ABC Urbain', [], [
            ['code_geographique' => '11111', 'libelle_geographique' => 'Ville', 'population' => 50000, 'surface_km2' => 20.0],
        ]);
        $this->projet('abc-rural', 'ABC Rural', [], [
            ['code_geographique' => '22222', 'libelle_geographique' => 'Bourg', 'population' => 800, 'surface_km2' => 40.0],
        ]);

        $this->get('/abc?pop_min=10000')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-urbain')
        );

        // Densité du périmètre : 2 500 hab./km² pour l'urbain, 20 pour le rural.
        $this->get('/abc?tri=densite&sens=desc')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.items.0.id', 'abc-urbain')->where('liste.items.0.densite', 2500)
        );

        $this->get('/abc?densite_min=1000')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-urbain')
        );
    }

    public function test_les_communes_anomalie_sont_ecartees(): void
    {
        $this->projet('abc-anomalie', 'ABC Anomalie', [], [
            ['code_geographique' => '11111', 'libelle_geographique' => 'Valide', 'population' => 1000],
            ['code_geographique' => '22222', 'libelle_geographique' => 'Écartée', 'population' => 999999, 'anomalie' => true],
        ]);

        $this->get('/abc')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.items.0.population', 1000)->where('liste.items.0.nb_communes', 1)
        );
    }

    public function test_recherche_texte_sur_une_commune(): void
    {
        $this->projet('abc-a', 'ABC Alpha', [], [
            ['code_geographique' => '11111', 'libelle_geographique' => 'Saint-Machin'],
        ]);
        $this->projet('abc-b', 'ABC Beta');

        $this->get('/abc?q=saint-machin')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-a')
        );
    }

    public function test_filtres_region_et_departement(): void
    {
        $this->projet('abc-bzh', 'ABC Breton', [], [
            ['code_geographique' => '35001', 'libelle_geographique' => 'Rennes',
                'region' => '53', 'departement' => '35', 'libelle_departement' => 'Ille-et-Vilaine'],
        ]);
        $this->projet('abc-idf', 'ABC Parisien', [], [
            ['code_geographique' => '75001', 'libelle_geographique' => 'Paris',
                'region' => '11', 'departement' => '75', 'libelle_departement' => 'Paris'],
        ]);

        $this->get('/abc?region=bretagne')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-bzh')
        );

        $this->get('/abc?departement=75')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-idf')
        );
    }

    public function test_filtres_patrimoine_espece_habitat_et_aires(): void
    {
        $this->projet('abc-patrimoine', 'ABC Patrimoine', [], [
            ['code_geographique' => '35001', 'libelle_geographique' => 'Rennes'],
        ]);
        $this->projet('abc-nu', 'ABC Sans Rien');
        $this->patrimoine('35001');

        $this->get('/abc?espece=loutre')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-patrimoine')
        );

        $this->get('/abc?espece=60789')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-patrimoine')
        );

        $this->get('/abc?habitat=2110')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-patrimoine')
        );

        $this->get('/abc?aires_min=2')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)
                ->where('liste.items.0.id', 'abc-patrimoine')
                ->where('liste.items.0.nb_aires', 2)
                ->where('liste.items.0.nb_znieff', 2)
                ->where('liste.items.0.nb_natura', 1)
        );

        // Les deux aires touchent la même commune : le comptage déduplique.
        $this->get('/abc?aires_min=3')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 0)
        );
    }

    public function test_filtre_annee_et_source(): void
    {
        $this->projet('abc-recent', 'ABC Récent', ['annee_debut' => 2025, 'source' => 'fondsvert-p113-2025']);
        $this->projet('abc-ancien', 'ABC Ancien', ['annee_debut' => 2015, 'source' => 'wayback']);

        $this->get('/abc?annee_min=2020')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-recent')
        );

        $this->get('/abc?source=wayback')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 1)->where('liste.items.0.id', 'abc-ancien')
        );
    }

    public function test_tri_inconnu_retombe_sur_le_defaut(): void
    {
        $this->projet('abc-a', 'ABC Alpha');

        $this->get('/abc?tri=inconnu&sens=invalide')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.query.tri', 'nom')->where('liste.query.sens', 'asc')
        );
    }

    public function test_pagination_et_page_hors_borne(): void
    {
        foreach (range(1, 55) as $i) {
            $this->projet(sprintf('abc-%02d', $i), sprintf('ABC %02d', $i));
        }

        $this->get('/abc')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.total', 55)->where('liste.pages', 2)->where('liste.page', 1)
        );

        $this->get('/abc?page=2')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.page', 2)->count('liste.items', 5)
        );

        // Hors borne : rabattue sur la dernière page, pas d'erreur.
        $this->get('/abc?page=99')->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('liste.page', 2)
        );
    }
}
