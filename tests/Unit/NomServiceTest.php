<?php

namespace Tests\Unit;

use App\Services\NomService;
use Tests\TestCase;

class NomServiceTest extends TestCase
{
    private NomService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NomService;
    }

    public function test_guillemets_csv_residuels_et_espaces_multiples(): void
    {
        $this->assertSame(
            "ABC 'Perseigne' PNR Normandie-Maine (ligérien)",
            $this->service->projet("ABC 'Perseigne'\" PNR Normandie-Maine (ligérien)\"")
        );
        $this->assertSame(
            'ABC de Ouest Aveyron Communauté',
            $this->service->projet('ABC  de Ouest Aveyron Communauté')
        );
    }

    public function test_structure_porteuse_concatenee_au_nom_est_retiree(): void
    {
        $porteur = "COMMUNAUTE DE COMMUNES RIVES DE L'AIN PAYS DU CERDON";

        $this->assertSame(
            'ABC Ain-Cerdon',
            $this->service->projet("ABC Ain-Cerdon  {$porteur}", $porteur)
        );
    }

    public function test_porteur_au_nom_simple_nest_pas_retire(): void
    {
        // Le porteur n'est pas une concaténation : il est simplement le nom
        // de la commune, déjà présent naturellement dans le nom du projet.
        $this->assertSame('ABC d\'Angers', $this->service->projet('ABC d\'Angers', 'Angers'));
    }

    public function test_nom_et_porteur_propres_restent_inchanges(): void
    {
        $this->assertSame('ABC Alba-la-Romaine', $this->service->projet('ABC Alba-la-Romaine', 'Alba-la-Romaine'));
        $this->assertSame('', $this->service->projet(null));
        $this->assertSame('ABC X', $this->service->projet('ABC X'));
    }

    public function test_structure_porteuse_nettoyee(): void
    {
        $this->assertSame('CC VAL DE SOMME', $this->service->structurePorteuse('  CC   VAL DE SOMME '));
        $this->assertNull($this->service->structurePorteuse(null));
        $this->assertNull($this->service->structurePorteuse('   '));
        $this->assertSame('CA du Grand Angoulême', $this->service->structurePorteuse('"CA du Grand Angoulême"'));
    }
}
