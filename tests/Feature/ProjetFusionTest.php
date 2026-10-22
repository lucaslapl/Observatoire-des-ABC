<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contribution;
use App\Models\Financement;
use App\Models\ProjectAlias;
use App\Models\Projet;
use App\Models\User;
use App\Models\Verification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Fusion d'un doublon suspect depuis le panneau d'administration
 * (POST /api/admin/projets/{doublon}/fusion) : même pipeline que
 * abc:merge-projects — union sans perte, alias + 301, audit, snapshot
 * de rollback. Réservé aux administrateurs, comme la suppression.
 */
class ProjetFusionTest extends TestCase
{
    use DatabaseMigrations;

    private Projet $doublon;

    private Projet $canonique;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('abc');

        $this->canonique = Projet::create([
            'id' => 'abc-fusion-canonical-test-2021',
            'nom' => 'ABC de Fusion-Cano',
            'slug' => 'abc-de-fusion-cano',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2021,
        ]);

        $this->doublon = Projet::create([
            'id' => 'abc-fusion-doublon-test-2021',
            'nom' => 'ABC de Fusion-Doublon',
            'slug' => 'abc-de-fusion-doublon',
            'statut' => 'en_cours',
            'source' => 'fonds_vert',
            'annee_debut' => 2021,
        ]);

        Verification::create([
            'projet_id' => $this->doublon->id,
            'etat' => 'a_verifier',
            'note_auto' => 'Doublon suspect (Fonds vert P113 2024 ligne x) : semble financer ce projet, mais d\'autres projets portent le même nom.',
        ]);

        Financement::create([
            'project_id' => $this->doublon->id,
            'source' => 'fonds_vert_2024',
            'ligne_id' => 'ligne-fusion-1',
            'beneficiaire' => 'Commune de Fusion-Doublon',
            'annee_financement' => 2024,
            'montant' => '50000',
            'ligne_brute' => 'Atlas de la biodiversité communale;Commune de Fusion-Doublon;50000',
        ]);
    }

    public function test_admin_peut_fusionner_un_doublon(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            "/api/admin/projets/{$this->doublon->id}/fusion",
            [
                'canonique_id' => $this->canonique->id,
                'motif' => 'même ABC décrit sous deux noms',
            ]
        );

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertDatabaseMissing('projets', ['id' => $this->doublon->id]);
        $this->assertDatabaseHas('project_aliases', [
            'project_id' => $this->canonique->id,
            'alias_project_id' => $this->doublon->id,
            'alias_slug' => 'abc-de-fusion-doublon',
            'created_by' => 'panneau-admin',
        ]);
        $this->assertDatabaseHas('financements', [
            'project_id' => $this->canonique->id,
            'ligne_id' => 'ligne-fusion-1',
        ]);

        $audit = AuditLog::where('action', 'fusion')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->email, $audit->par_admin);

        // Snapshot de rollback écrit.
        $this->assertCount(1, Storage::disk('abc')->files('merges'));
    }

    public function test_contribution_et_verification_migrent_sans_perte(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Contribution::create([
            'projet_id' => $this->doublon->id,
            'type' => 'note',
            'payload_json' => ['note' => 'note du contributeur'],
            'statut' => 'validee',
        ]);

        $this->actingAs($admin)->postJson("/api/admin/projets/{$this->doublon->id}/fusion", [
            'canonique_id' => $this->canonique->id,
        ])->assertOk();

        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->canonique->id,
            'statut' => 'validee',
        ]);
        // Le travail humain (note automatique du doublon) est concaténé,
        // jamais perdu.
        $this->assertDatabaseHas('verifications', ['projet_id' => $this->canonique->id]);
        $verif = Verification::find($this->canonique->id);
        $this->assertStringContainsString('Doublon suspect', (string) $verif->note_auto);
    }

    public function test_moderateur_et_contributeur_ne_peuvent_pas_fusionner(): void
    {
        $moderateur = User::factory()->create();
        $moderateur->assignRole('moderateur');

        $this->actingAs($moderateur)
            ->postJson("/api/admin/projets/{$this->doublon->id}/fusion", [
                'canonique_id' => $this->canonique->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('projets', ['id' => $this->doublon->id]);
    }

    public function test_fusion_refusee_si_canonique_est_deja_un_alias(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $autre = Projet::create([
            'id' => 'abc-fusion-autre-test-2021',
            'nom' => 'ABC de Fusion-Autre',
            'slug' => 'abc-de-fusion-autre',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2021,
        ]);
        ProjectAlias::create([
            'project_id' => $autre->id,
            'alias_project_id' => $this->canonique->id,
            'alias_slug' => $this->canonique->slug,
            'source' => 'data.gouv',
            'motif' => '',
            'created_by' => 'test',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/projets/{$this->doublon->id}/fusion", [
                'canonique_id' => $this->canonique->id,
            ])
            ->assertStatus(400)
            ->assertJsonPath('error', 'abc-fusion-canonical-test-2021 est déjà un alias de abc-fusion-autre-test-2021 : fusionner directement le canonique réel.');
    }

    public function test_recherche_de_projets_par_nom(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // Insensible à la casse, sur PostgreSQL comme en test (SQLite).
        $this->actingAs($admin)
            ->getJson('/api/admin/projets/recherche?q=fusion-cano')
            ->assertOk()
            ->assertJsonPath('projets.0.id', $this->canonique->id);

        $this->actingAs($admin)
            ->getJson('/api/admin/projets/recherche?q=inconnu-au-bataillon')
            ->assertOk()
            ->assertJsonPath('projets.0.id', null);
    }
}
