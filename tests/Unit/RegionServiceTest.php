<?php

namespace Tests\Unit;

use App\Services\RegionService;
use Tests\TestCase;

class RegionServiceTest extends TestCase
{
    private RegionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RegionService;
    }

    public function test_label_depuis_le_code_insee(): void
    {
        $this->assertSame('Bretagne', $this->service->label('53'));
        $this->assertSame('Auvergne-Rhône-Alpes', $this->service->label('84'));
    }

    public function test_label_deaccidente_ou_mal_case_est_rattrape(): void
    {
        // Les sources CSV perdent parfois les accents ou la casse.
        $this->assertSame('Auvergne-Rhône-Alpes', $this->service->label('Auvergne-Rhone-Alpes'));
        $this->assertSame('Auvergne-Rhône-Alpes', $this->service->label('AUVERGNE-RHONE-ALPES'));
        $this->assertSame("Provence-Alpes-Côte d'Azur", $this->service->label("Provence-Alpes-Cote d'Azur"));
        $this->assertSame('Île-de-France', $this->service->label('Ile de france'));
    }

    public function test_libelle_hors_referentiel_est_conserve(): void
    {
        $this->assertSame('Nouvelle-Calédonie', $this->service->label('Nouvelle-Calédonie'));
    }

    public function test_valeurs_vides_ou_numeriques_inconnues(): void
    {
        $this->assertNull($this->service->label(null));
        $this->assertNull($this->service->label(''));
        $this->assertNull($this->service->label('999'));
    }
}
