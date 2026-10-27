<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjetDemographieTest extends TestCase
{
    use RefreshDatabase;

    private function projetAvecCommunes(string $id, array $communes): Projet
    {
        $projet = Projet::create([
            'id' => $id,
            'nom' => 'ABC '.$id,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        foreach ($communes as $c) {
            Commune::create(['projet_id' => $id] + $c);
        }

        return $projet;
    }

    public function test_mono_commune_affiche_population_superficie_et_densite(): void
    {
        $projet = $this->projetAvecCommunes('abc-mono', [
            [
                'code_geographique' => '35282',
                'libelle_geographique' => 'Rives-du-Couesnon',
                'population' => 2927,
                'surface_km2' => 49.13,
            ],
        ]);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.demographie.population_totale', 2927)
                ->where('projet.demographie.communes_avec_donnees', 1)
                ->where('projet.demographie.mono_commune', true)
                ->where('projet.demographie.superficie_km2', 49.13)
                ->where('projet.demographie.densite', 59.6)
        );
    }

    public function test_multi_communes_affiche_la_somme_sans_superficie_ni_densite(): void
    {
        $projet = $this->projetAvecCommunes('abc-multi', [
            [
                'code_geographique' => '56001',
                'libelle_geographique' => 'Vannes',
                'population' => 54000,
                'surface_km2' => 32.3,
            ],
            [
                'code_geographique' => '56002',
                'libelle_geographique' => 'Arradon',
                'population' => 6000,
                'surface_km2' => 12.0,
            ],
            [
                'code_geographique' => '56003',
                'libelle_geographique' => 'Sans données',
            ],
        ]);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.demographie.population_totale', 60000)
                ->where('projet.demographie.communes_avec_donnees', 2)
                ->where('projet.demographie.mono_commune', false)
                ->where('projet.demographie.superficie_km2', null)
                ->where('projet.demographie.densite', null)
        );
    }

    public function test_les_communes_ecartees_comme_anomalie_ne_comptent_pas(): void
    {
        $projet = $this->projetAvecCommunes('abc-ano', [
            [
                'code_geographique' => '56001',
                'libelle_geographique' => 'Vannes',
                'population' => 54000,
            ],
            [
                'code_geographique' => '75056',
                'libelle_geographique' => 'Paris (erreur de saisie)',
                'population' => 2103778,
                'anomalie' => true,
            ],
        ]);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.demographie.population_totale', 54000)
                ->where('projet.demographie.communes_avec_donnees', 1)
        );
    }

    public function test_sans_donnees_demographiques_la_section_est_absente(): void
    {
        $projet = $this->projetAvecCommunes('abc-nodata', [
            [
                'code_geographique' => '97111',
                'libelle_geographique' => 'Capesterre-Belle-Eau',
            ],
        ]);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.demographie', null)
        );
    }

    public function test_page_commune_affiche_population_superficie_densite(): void
    {
        $this->projetAvecCommunes('abc-geo', [
            [
                'code_geographique' => '35282',
                'libelle_geographique' => 'Rives-du-Couesnon',
                'population' => 2927,
                'surface_km2' => 49.13,
            ],
        ]);

        $this->get('/commune/35282')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Commune')
                ->where('commune.demographie.population', 2927)
                ->where('commune.demographie.surface_km2', 49.13)
                ->where('commune.demographie.densite', 59.6)
        );
    }

    public function test_page_commune_sans_donnees_demographie_absente(): void
    {
        $this->projetAvecCommunes('abc-geo-ko', [
            [
                'code_geographique' => '97111',
                'libelle_geographique' => 'Capesterre-Belle-Eau',
            ],
        ]);

        $this->get('/commune/97111')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Commune')
                ->where('commune.demographie', null)
        );
    }
}
