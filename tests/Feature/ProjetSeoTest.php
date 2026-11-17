<?php

namespace Tests\Feature;

use App\Models\Commune;
use App\Models\Projet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * SEO de la fiche projet (audit SEO du 2026-11-17, C3/C4) : la payload de
 * /abc/{slug} doit porter tout ce que le front (module resources/js/seo/
 * projetSeo.js, testé par tests/js) assemble en title, description et
 * JSON-LD — département majoritaire compris. Sans SSR en local, le HTML
 * servi n'embarque pas le rendu : c'est la payload qui est le contrat.
 */
class ProjetSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_fiche_mono_commune_expose_tout_le_socle_seo(): void
    {
        $projet = Projet::create([
            'id' => 'abc-de-murs-erigne-murs-erigne-2020',
            'nom' => 'ABC de Mûrs-Erigné',
            'slug' => 'abc-de-murs-erigne',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2020,
            'structure_porteuse' => 'Commune de Mûrs-Erigné',
        ]);
        Commune::create([
            'projet_id' => $projet->id,
            'code_geographique' => '49216',
            'libelle_geographique' => 'Mûrs-Erigné',
            'departement' => '49',
            'libelle_departement' => 'Maine-et-Loire',
            'region' => 'Pays de la Loire',
            'population' => 5934,
            'anomalie' => false,
        ]);

        $this->get('/abc/abc-de-murs-erigne')->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                // Title/description du front : « ABC de Mûrs-Erigné (49,
                // Maine-et-Loire) — Commune de Mûrs-Erigné », « 1 commune ».
                ->where('projet.departement', ['code' => '49', 'label' => 'Maine-et-Loire'])
                ->where('projet.region_label', 'Pays de la Loire')
                ->where('projet.structure_porteuse', 'Commune de Mûrs-Erigné')
                ->where('projet.communes.0.libelle', 'Mûrs-Erigné')
                ->where('projet.communes.0.departement_code', '49')
                ->where('projet.communes.0.departement_label', 'Maine-et-Loire')
                // Fait saillant pour la meta description.
                ->where('projet.demographie.population_totale', 5934)
                // patrimoine_resume : comptages INPN (page dédiée + description).
                ->has('patrimoine_resume.present')
                ->has('patrimoine_resume.nb_znieff')
        );
    }

    public function test_fiche_expose_le_departement_majoritaire_du_perimetre(): void
    {
        // Périmètre éclaté sur deux départements : 3 communes dans le Rhône,
        // 1 dans l'Ain — le niveau département du fil d'Ariane (T4) et le
        // badge d'entête prennent le Rhône, comme le fait le regionLabel
        // majoritaire de la page patrimoine.
        $projet = Projet::create([
            'id' => 'abc-smpmo-2023',
            'nom' => 'ABC du Syndicat Mixte Plaines Monts d’Or (SMPMO)',
            'slug' => 'abc-du-syndicat-mixte-plaines-monts-dor-smpmo',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);
        foreach ([
            ['69047', 'Charbonnières-les-Bains', '69', 'Rhône'],
            ['69056', 'Chessy', '69', 'Rhône'],
            ['69070', 'Couzon-au-Mont-d’Or', '69', 'Rhône'],
            ['01004', 'Arbent', '01', 'Ain'],
        ] as [$code, $libelle, $dept, $deptLibelle]) {
            Commune::create([
                'projet_id' => $projet->id,
                'code_geographique' => $code,
                'libelle_geographique' => $libelle,
                'departement' => $dept,
                'libelle_departement' => $deptLibelle,
                'region' => 'Auvergne-Rhône-Alpes',
                'anomalie' => false,
            ]);
        }

        $this->get("/abc/{$projet->slug}")->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.departement', ['code' => '69', 'label' => 'Rhône'])
                ->where('projet.region_label', 'Auvergne-Rhône-Alpes')
                ->has('projet.communes', 4)
        );

        // Le fil d'Ariane de la fiche pointe le département majoritaire :
        // la cible du lien doit exister (route /departement/{code}).
        $this->get('/departement/69')->assertOk();
    }

    public function test_fiche_sans_commune_n_expose_pas_de_departement(): void
    {
        $projet = Projet::create([
            'id' => 'abc-sans-perimetre-2024',
            'nom' => 'ABC sans périmètre connu',
            'slug' => 'abc-sans-perimetre-connu',
            'statut' => 'inconnu',
            'source' => 'fondsvert-p113-2024',
        ]);

        $this->get("/abc/{$projet->slug}")->assertOk()->assertInertia(
            fn (Assert $page) => $page->component('Projet')
                ->where('projet.departement', ['code' => null, 'label' => null])
                ->where('projet.communes', [])
        );
    }
}
