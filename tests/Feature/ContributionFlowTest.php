<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use App\Models\Verification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContributionFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $projetId = 'abc-test-ville';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Projet::create([
            'id' => $this->projetId,
            'nom' => 'ABC Test Ville',
            'structure_porteuse' => 'Ville Test',
            'annee_debut' => 2020,
            'statut' => 'en_cours',
            'source' => 'datagouv',
        ]);
    }

    public function test_public_contribution_is_created(): void
    {
        $response = $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'statut_suggere' => 'termine',
            'commentaire' => 'Vu sur le site officiel',
        ]);

        $response->assertCreated();
        $response->assertJson(['ok' => true]);
        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'statut' => 'en_attente',
        ]);
    }

    public function test_contribution_rejects_invalid_type(): void
    {
        $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'bidon',
        ])->assertStatus(422);
    }

    public function test_contribution_unknown_projet_returns_404(): void
    {
        $this->postJson('/api/contributions', [
            'projet_id' => 'n-existe-pas',
            'type' => 'note',
            'note' => 'bonjour',
        ])->assertNotFound();
    }

    public function test_contribution_payload_keeps_string_values(): void
    {
        $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'date_debut',
            'annee_debut_suggeree' => 2019,
            'source' => 'Ville Test',
        ])->assertCreated();

        $c = Contribution::first();
        $this->assertSame('2019', $c->payload_json['annee_debut_suggeree']);
        $this->assertSame('Ville Test', $c->payload_json['source']);
    }

    public function test_admin_can_validate_contribution(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'statut_suggere' => 'termine',
        ])->assertCreated();

        $id = Contribution::first()->id;

        $this->actingAs($admin)
            ->postJson("/api/admin/contributions/{$id}/valider")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('contributions', ['id' => $id, 'statut' => 'validee']);
        $v = Verification::where('projet_id', $this->projetId)->first();
        $this->assertNotNull($v);
        $this->assertSame('confirme_termine', $v->etat);
        $this->assertDatabaseHas('audit_log', ['contribution_id' => $id, 'action' => 'validee']);
    }

    public function test_non_admin_cannot_moderate(): void
    {
        $contributor = User::factory()->create();
        $contributor->assignRole('contributeur');

        $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'note',
            'note' => 'note de test',
        ])->assertCreated();
        $id = Contribution::first()->id;

        $this->actingAs($contributor)
            ->postJson("/api/admin/contributions/{$id}/valider")
            ->assertForbidden();
    }

    public function test_guest_cannot_moderate(): void
    {
        $this->getJson('/api/admin/contributions')
            ->assertUnauthorized();
    }

    public function test_admin_can_reject_and_retire_contribution(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'statut_suggere' => 'en_cours',
        ])->assertCreated();
        $id = Contribution::first()->id;

        $this->actingAs($admin)
            ->postJson("/api/admin/contributions/{$id}/refuser", ['note_admin' => 'doublon'])
            ->assertOk();

        $this->assertDatabaseHas('contributions', ['id' => $id, 'statut' => 'refusee', 'note_admin' => 'doublon']);
    }

    public function test_public_contribution_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/contributions', [
                'projet_id' => $this->projetId,
                'type' => 'note',
                'note' => "note {$i}",
            ])->assertCreated();
        }

        $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'note',
            'note' => 'trop',
        ])->assertStatus(429);
    }
}
