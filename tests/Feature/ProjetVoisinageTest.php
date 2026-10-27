<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjetVoisinageTest extends TestCase
{
    use RefreshDatabase;

    private function seedProjetAvecCommune(
        string $id,
        string $nom,
        string $code,
        ?float $lat,
        ?float $lon,
        bool $anomalie = false,
    ): Projet {
        $projet = Projet::create([
            'id' => $id,
            'nom' => $nom,
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        Commune::create([
            'projet_id' => $id,
            'code_geographique' => $code,
            'libelle_geographique' => $nom,
            'lat' => $lat,
            'lon' => $lon,
            'anomalie' => $anomalie,
        ]);

        return $projet;
    }

    public function test_proche_voisin_liste_avec_distance(): void
    {
        // 48.05° contre 48.00° : ~5,6 km.
        $projet = $this->seedProjetAvecCommune('abc-a', 'ABC du Centre', '56001', 48.00, -3.00);
        $this->seedProjetAvecCommune('abc-b', 'ABC voisin', '56002', 48.05, -3.00);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.voisinage_km', 15)
                ->has('projet.voisins', 1)
                ->where('projet.voisins.0.projet_id', 'abc-b')
                ->where('projet.voisins.0.nom', 'ABC voisin')
                ->where('projet.voisins.0.slug', 'abc-voisin')
                ->where('projet.voisins.0.commune', 'ABC voisin')
                ->where('projet.voisins.0.distance_km', 5.6)
        );
    }

    public function test_voisins_tries_par_distance_et_limite_au_rayon(): void
    {
        // 0,13° ≈ 14,5 km (dans le rayon) ; 0,14° ≈ 15,6 km (dehors).
        $projet = $this->seedProjetAvecCommune('abc-a', 'ABC du Centre', '56001', 48.00, -3.00);
        $this->seedProjetAvecCommune('abc-loin', 'ABC lointain', '56010', 48.14, -3.00);
        $this->seedProjetAvecCommune('abc-proche', 'ABC proche', '56011', 48.02, -3.00);
        $this->seedProjetAvecCommune('abc-moyen', 'ABC moyen', '56012', 48.13, -3.00);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->has('projet.voisins', 2)
                ->where('projet.voisins.0.projet_id', 'abc-proche')
                ->where('projet.voisins.1.projet_id', 'abc-moyen')
        );
    }

    public function test_voisins_vide_quand_aucun_dans_le_rayon(): void
    {
        $projet = $this->seedProjetAvecCommune('abc-a', 'ABC du Centre', '56001', 48.00, -3.00);
        $this->seedProjetAvecCommune('abc-loin', 'ABC lointain', '56010', 48.40, -3.00);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.voisins', [])
        );
    }

    public function test_voisins_null_quand_aucune_commune_geocodee(): void
    {
        $projet = $this->seedProjetAvecCommune('abc-a', 'ABC du Centre', '56001', null, null);
        $this->seedProjetAvecCommune('abc-b', 'ABC voisin', '56002', 48.00, -3.00);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.voisins', null)
        );
    }

    public function test_communes_anormales_ignorees_des_deux_cotes(): void
    {
        // Candidat anormal (donc géographiquement suspect) à côté : ignoré.
        $projet = $this->seedProjetAvecCommune('abc-a', 'ABC du Centre', '56001', 48.00, -3.00);
        $this->seedProjetAvecCommune('abc-anormal', 'ABC anormal', '56002', 48.01, -3.00, true);
        // Référence du projet anormale : elle ne doit rien détecter.
        $projet2 = $this->seedProjetAvecCommune('abc-c', 'ABC tout anormal', '56003', 48.00, -2.50, true);
        $this->seedProjetAvecCommune('abc-b', 'ABC voisin', '56004', 48.00, -2.50);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('projet.voisins', [])
        );
        $this->get('/abc/'.$projet2->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('projet.voisins', null)
        );
    }

    public function test_commune_partagee_a_distance_nulle(): void
    {
        $projet = $this->seedProjetAvecCommune('abc-a', 'ABC du Centre', '56001', 48.00, -3.00);
        $this->seedProjetAvecCommune('abc-b', 'ABC voisin', '56001', 48.00, -3.00);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->has('projet.voisins', 1)
                ->where('projet.voisins.0.projet_id', 'abc-b')
                ->where('projet.voisins.0.distance_km', 0)
        );
    }

    public function test_le_projet_lui_meme_n_est_pas_son_voisin(): void
    {
        $projet = $this->seedProjetAvecCommune('abc-a', 'ABC du Centre', '56001', 48.00, -3.00);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '56002',
            'libelle_geographique' => 'Deuxième commune',
            'lat' => 48.01,
            'lon' => -3.00,
            'anomalie' => false,
        ]);

        $this->get('/abc/'.$projet->slug)->assertOk()->assertInertia(
            fn (Assert $page) => $page->where('projet.voisins', [])
        );
    }
}
