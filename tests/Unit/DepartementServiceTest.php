<?php

namespace Tests\Unit;

use App\Services\DepartementService;
use Tests\TestCase;

class DepartementServiceTest extends TestCase
{
    private DepartementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DepartementService;
    }

    public function test_label_canonical_depuis_le_referentiel(): void
    {
        $this->assertSame('Eure', $this->service->label('27'));
        $this->assertSame('Rhône', $this->service->label('69'));
        $this->assertSame('Rhône', $this->service->label(' 69 '));
        $this->assertSame('Corse-du-Sud', $this->service->label('2A'));
        $this->assertSame('Haute-Corse', $this->service->label('2b'));
        $this->assertSame('La Réunion', $this->service->label('974'));
    }

    public function test_label_inconnu_ou_vide(): void
    {
        $this->assertNull($this->service->label(null));
        $this->assertNull($this->service->label(''));
        $this->assertNull($this->service->label('999'));
        $this->assertNull($this->service->label('Rhone'));
    }

    public function test_code_depuis_le_prefixe_du_code_insee(): void
    {
        // Louviers : la colonne `departement` est vide dans certaines
        // sources, le code se dérive du préfixe INSEE.
        $this->assertSame('27', $this->service->codeDepuisInsee('27375'));
        $this->assertSame('01', $this->service->codeDepuisInsee('01000'));
        $this->assertSame('2a', $this->service->codeDepuisInsee('2A004'));
        $this->assertSame('971', $this->service->codeDepuisInsee('97123'));
        $this->assertSame('976', $this->service->codeDepuisInsee('97611'));
        $this->assertSame('988', $this->service->codeDepuisInsee('98801'));
    }

    public function test_codes_metropolitains_commencant_par_9_restant_sur_deux_caracteres(): void
    {
        // Seine-Saint-Denis, Val-de-Marne, Val-d'Oise : ne pas confondre
        // avec l'outre-mer (97x/98x), qui seul prend 3 caractères.
        $this->assertSame('93', $this->service->codeDepuisInsee('93001'));
        $this->assertSame('94', $this->service->codeDepuisInsee('94000'));
        $this->assertSame('95', $this->service->codeDepuisInsee('95200'));
    }

    public function test_code_depuis_insee_vide(): void
    {
        $this->assertNull($this->service->codeDepuisInsee(null));
        $this->assertNull($this->service->codeDepuisInsee(''));
        $this->assertNull($this->service->codeDepuisInsee('  '));
    }
}
