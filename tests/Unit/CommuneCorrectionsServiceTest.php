<?php

namespace Tests\Unit;

use App\Services\CommuneCorrectionsService;
use Tests\TestCase;

class CommuneCorrectionsServiceTest extends TestCase
{
    private CommuneCorrectionsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(CommuneCorrectionsService::class);
    }

    /** Ligne du registre OFB (data.gouv) telle que publiée pour l'ABC du Nord de la Martinique. */
    private function ligneRegistre(): array
    {
        return [
            'code_geographique' => '97416',
            'libelle_geographique' => 'Saint-Pierre',
            'epci' => '249740077',
            'libelle_epci' => 'Communauté Intercommunale des Villes Solidaires',
            'departement' => '974',
            'libelle_departement' => 'La Réunion',
            'region' => 'La Réunion',
            'libelle_pnr' => null,
        ];
    }

    public function test_saint_pierre_de_labc_du_nord_de_la_martinique_est_corrigee_en_martinique(): void
    {
        $c = $this->service->corrigerCommune($this->ligneRegistre(), 'abc-du-nord-de-la-martinique-martinique');

        $this->assertSame('97222', $c['code_geographique']);
        $this->assertSame('Saint-Pierre', $c['libelle_geographique']);
        $this->assertSame('972', $c['departement']);
        $this->assertSame('Martinique', $c['libelle_departement']);
        $this->assertSame('2', $c['region']);
        $this->assertSame('PNR de Martinique', $c['libelle_pnr']);
        $this->assertSame('200041788', $c['epci']);
        $this->assertSame("Communauté d'agglomération du Pays Nord Martinique", $c['libelle_epci']);
    }

    public function test_saint_pierre_reunion_reste_valable_pour_un_autre_projet(): void
    {
        // 97416 est un code INSEE valide : une correction globale casserait
        // un futur ABC réunionnais incluant réellement Saint-Pierre (974).
        $c = $this->service->corrigerCommune($this->ligneRegistre(), 'abc-de-saint-pierre-reunion');

        $this->assertSame('97416', $c['code_geographique']);
        $this->assertSame('La Réunion', $c['libelle_departement']);
    }

    public function test_correction_ciblee_pour_le_projet_wayback_de_martinique(): void
    {
        // Si le projet disparaissait du registre, l'instantané Wayback 2022
        // (qui porte la même erreur 97416) le recréerait sous cet id.
        $c = $this->service->corrigerCommune([
            'code_geographique' => '97416',
            'libelle_geographique' => 'Saint-Pierre',
        ], 'abc-du-nord-de-la-martinique-parc-naturel-regional');

        $this->assertSame('97222', $c['code_geographique']);
        $this->assertSame('972', $c['departement']);
        $this->assertSame('Martinique', $c['libelle_departement']);
    }

    public function test_corrections_globales_par_code_sont_toujours_appliquees(): void
    {
        $c = $this->service->corrigerCommune([
            'code_geographique' => '60145',
            'libelle_geographique' => 'Chelles',
        ]);

        $this->assertSame('77108', $c['code_geographique']);
        $this->assertSame('Seine-et-Marne', $c['libelle_departement']);
    }

    public function test_commune_sans_correction_est_inchangee(): void
    {
        $ligne = [
            'code_geographique' => '97201',
            'libelle_geographique' => "L'Ajoupa-Bouillon",
        ];

        $this->assertSame($ligne, $this->service->corrigerCommune($ligne, 'abc-du-nord-de-la-martinique-martinique'));
    }
}
