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
use Inertia\Testing\AssertableInertia as Assert;
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

    private string $noteDoublon = "Doublon suspect (Fonds vert P113 2024 ligne fv-ligne-x) : « ABC de Test Fusion » (bénéficiaire : Commune de Test) semble financer ce projet, mais d'autres projets portent le même nom.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake('abc');

        // Deux projets homonymes (même nom normalisé, sources distinctes) :
        // la situation exacte qui déclenche « Doublon suspect » au collect.
        $this->canonique = Projet::create([
            'id' => 'abc-fusion-canonical-test-2021',
            'nom' => 'ABC de Test Fusion',
            'slug' => 'abc-de-test-fusion',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
            'annee_debut' => 2021,
        ]);

        $this->doublon = Projet::create([
            'id' => 'abc-fusion-doublon-test-2021',
            'nom' => 'ABC de Test Fusion',
            'slug' => 'abc-de-test-fusion-2021-bis',
            'statut' => 'en_cours',
            'source' => 'fonds_vert',
            'annee_debut' => 2021,
        ]);

        Verification::create([
            'projet_id' => $this->doublon->id,
            'etat' => 'a_verifier',
            'note_auto' => $this->noteDoublon,
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
            'alias_slug' => 'abc-de-test-fusion-2021-bis',
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

    public function test_la_fusion_est_visible_dans_le_journal_et_lexport(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->postJson("/api/admin/projets/{$this->doublon->id}/fusion", [
            'canonique_id' => $this->canonique->id,
            'motif' => 'même ABC décrit sous deux noms',
        ])->assertOk();

        // Panneau d'administration : journal des fusions — qui a fusionné
        // quoi, dans quoi, pourquoi, et quand.
        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('journalFusions', 1)
                ->where('journalFusions.0.doublon', 'ABC de Test Fusion')
                ->where('journalFusions.0.doublon_id', $this->doublon->id)
                ->where('journalFusions.0.canonique_id', $this->canonique->id)
                ->where('journalFusions.0.motif', 'même ABC décrit sous deux noms')
                ->where('journalFusions.0.par', $admin->email));

        // Export CSV d'audit : la fusion y figure, avec son auteur.
        $response = $this->actingAs($admin)->get('/api/admin/exclusions/export');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('fusion', $csv);
        $this->assertStringContainsString($this->doublon->id, $csv);
        $this->assertStringContainsString($admin->email, $csv);
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
        // Le travail humain (la vérification) migre, mais le « Doublon
        // suspect » — signal automatique dérivable — disparaît : la fusion
        // vient de trancher l'ambiguïté (plus d'homonyme).
        $verif = Verification::find($this->canonique->id);
        $this->assertNotNull($verif);
        $this->assertNull($verif->note_auto);
    }

    public function test_doublon_suspect_conserve_si_un_autre_homonyme_persiste(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Projet::create([
            'id' => 'abc-fusion-troisieme-test-2021',
            'nom' => 'ABC de Test Fusion',
            'slug' => 'abc-de-test-fusion-2021-ter',
            'statut' => 'va_debuter',
            'source' => 'fonds_vert',
            'annee_debut' => 2021,
        ]);

        $this->actingAs($admin)->postJson("/api/admin/projets/{$this->doublon->id}/fusion", [
            'canonique_id' => $this->canonique->id,
        ])->assertOk();

        // L'ambiguïté persiste avec le troisième homonyme : la note reste,
        // le collect la régénérerait de toute façon.
        $verif = Verification::find($this->canonique->id);
        $this->assertNotNull($verif);
        $this->assertStringContainsString('Doublon suspect', (string) $verif->note_auto);
        $this->assertEquals(1, substr_count((string) $verif->note_auto, 'Doublon suspect'));
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
            ->getJson('/api/admin/projets/recherche?q=test fusion')
            ->assertOk()
            ->assertJsonPath('projets.0.id', $this->canonique->id);

        $this->actingAs($admin)
            ->getJson('/api/admin/projets/recherche?q=inconnu-au-bataillon')
            ->assertOk()
            ->assertJsonPath('projets.0.id', null);
    }
}
