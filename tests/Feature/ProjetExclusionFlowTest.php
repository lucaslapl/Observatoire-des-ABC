<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\ProjectExclusion;
use App\Models\Projet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjetExclusionFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $projetId = 'abc-erronne-test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Projet::create([
            'id' => $this->projetId,
            'nom' => 'ABC Erronné Test',
            'statut' => 'a_venir',
            'source' => 'wayback',
        ]);
    }

    public function test_admin_can_delete_project_and_exclude_it(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/projets/{$this->projetId}", ['motif' => 'doublon wayback'])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseMissing('projets', ['id' => $this->projetId]);
        $this->assertDatabaseHas('project_exclusions', ['projet_id' => $this->projetId, 'motif' => 'doublon wayback']);
        $this->assertDatabaseHas('audit_log', ['action' => 'projet_supprime']);
    }

    public function test_guest_cannot_delete_project(): void
    {
        $this->deleteJson("/api/admin/projets/{$this->projetId}")
            ->assertStatus(401);

        $this->assertDatabaseHas('projets', ['id' => $this->projetId]);
        $this->assertDatabaseMissing('project_exclusions', ['projet_id' => $this->projetId]);
    }

    public function test_admin_can_lift_exclusion(): void
    {
        ProjectExclusion::create(['projet_id' => $this->projetId]);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->deleteJson("/api/admin/exclusions/{$this->projetId}")
            ->assertOk();

        $this->assertDatabaseMissing('project_exclusions', ['projet_id' => $this->projetId]);
        $this->assertDatabaseHas('audit_log', ['action' => 'exclusion_retiree']);
        AuditLog::query()->delete();
    }
}
