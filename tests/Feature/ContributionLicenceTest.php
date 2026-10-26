<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base légale de la rediffusion des corrections : l'acceptation explicite
 * de la Licence Ouverte 2.0 (case du formulaire de proposition, envoyée
 * comme accepte_licence) est enregistrée sur la contribution. Le champ
 * reste optionnel pour ne pas casser les clients existants (parité de
 * l'API historique) : sans lui, la trace vaut null.
 */
class ContributionLicenceTest extends TestCase
{
    use RefreshDatabase;

    private string $projetId = 'abc-licence';

    private User $contributeur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        Projet::create([
            'id' => $this->projetId,
            'nom' => 'ABC Test Licence',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->contributeur = User::factory()->create();
        $this->contributeur->assignRole('contributeur');
    }

    private function postContribution(array $body)
    {
        return $this->actingAs($this->contributeur)->postJson('/api/contributions', $body);
    }

    public function test_lacceptation_explicite_est_enregistree(): void
    {
        $response = $this->postContribution([
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'statut_suggere' => 'termine',
            'accepte_licence' => true,
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->projetId,
            'statut' => 'en_attente',
            'licence_acceptee' => true,
        ]);
    }

    public function test_sans_le_champ_la_contribution_rest_valide_sans_trace(): void
    {
        $response = $this->postContribution([
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'statut_suggere' => 'termine',
        ]);

        $response->assertCreated();

        $contribution = Contribution::query()->where('projet_id', $this->projetId)->first();
        $this->assertNotNull($contribution);
        $this->assertNull($contribution->licence_acceptee);

        // L'acceptation ne fuit pas dans le payload de correction.
        $this->assertArrayNotHasKey('accepte_licence', $contribution->payload_json);
    }

    public function test_le_champ_doit_etre_booleen(): void
    {
        $response = $this->postContribution([
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'statut_suggere' => 'termine',
            'accepte_licence' => 'peut-être',
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('accepte_licence', $response->json('errors'));
    }
}
