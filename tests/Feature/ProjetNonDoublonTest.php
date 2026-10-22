<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\DoublonDecision;
use App\Models\Projet;
use App\Models\User;
use App\Models\Verification;
use App\Services\DoublonSuspectService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

/**
 * Bouton « Ce n'est pas un doublon » de /verify (POST
 * /api/admin/projets/{projet}/non-doublon) : l'admin tranche que les
 * projets homonymes sont des ABC distincts. La note automatique
 * « Doublon suspect » disparaît pour tout le groupe, la décision est
 * persistée (doublon_decisions) pour que le collect ne la recrée pas, et
 * le tranchage est journalisé dans audit_log. Réservé aux administrateurs,
 * comme la fusion.
 */
class ProjetNonDoublonTest extends TestCase
{
    use DatabaseMigrations;

    private Projet $a;

    private Projet $b;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        // Deux projets homonymes (même nom normalisé), chacun avec sa note
        // automatique « Doublon suspect » d'une origine différente.
        $this->a = Projet::create([
            'id' => 'abc-non-doublon-a-2021',
            'nom' => 'ABC de Non Doublon',
            'slug' => 'abc-de-non-doublon',
            'statut' => 'termine',
            'source' => 'data.gouv',
            'annee_debut' => 2021,
        ]);
        $this->b = Projet::create([
            'id' => 'abc-non-doublon-b-2023',
            'nom' => 'ABC de non doublon',
            'slug' => 'abc-de-non-doublon-2023',
            'statut' => 'en_cours',
            'source' => 'wayback',
            'annee_debut' => 2023,
        ]);

        Verification::create([
            'projet_id' => $this->a->id,
            'etat' => 'a_verifier',
            'note_auto' => "Doublon suspect (homonymie) : « ABC de Non Doublon » est porté par 2 projets ({$this->a->id}, {$this->b->id}) — éditions distinctes ou doublon, à fusionner si nécessaire.",
        ]);
        Verification::create([
            'projet_id' => $this->b->id,
            'etat' => 'a_verifier',
            'note_auto' => "Doublon suspect (Fonds vert P113 2025 ligne 11314799) : « ABC de Non Doublon » semble financer ce projet, mais d'autres projets portent le même nom.",
        ]);
    }

    public function test_admin_peut_trancher_non_doublon(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson(
            "/api/admin/projets/{$this->a->id}/non-doublon",
            ['motif' => 'deux ABC distincts, territoires différents']
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('notes_retirees', 2);

        // Les notes automatiques « Doublon suspect » disparaissent pour tout
        // le groupe d'homonymes — l'état / verdict n'est pas touché.
        $this->assertNull(Verification::find($this->a->id)->note_auto);
        $this->assertNull(Verification::find($this->b->id)->note_auto);
        $this->assertSame('a_verifier', Verification::find($this->a->id)->etat);

        // La décision est persistée : le collect ne recréera pas la note.
        $this->assertDatabaseHas('doublon_decisions', [
            'nom_slug' => 'abc-de-non-doublon',
            'decision' => 'non_doublon',
            'motif' => 'deux ABC distincts, territoires différents',
            'par_admin' => $admin->email,
        ]);

        // Tranchage journalisé dans audit_log avec son auteur.
        $audit = AuditLog::where('action', 'doublon_non_confirme')->first();
        $this->assertNotNull($audit);
        $this->assertSame($admin->email, $audit->par_admin);
        $apres = json_decode($audit->apres, true);
        $this->assertSame($this->a->id, $apres['projet_id']);
        $this->assertSame(2, $apres['notes_retirees']);
    }

    public function test_le_collect_ne_recre_pas_les_notes_apres_tranchage(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin)
            ->postJson("/api/admin/projets/{$this->a->id}/non-doublon", [])
            ->assertOk();

        // Homonymies et nettoyer() respectent la décision humaine.
        $signales = app(DoublonSuspectService::class)->signalerHomonymes();
        $this->assertSame(0, $signales);
        $this->assertNull(Verification::find($this->a->id)->note_auto);
        $this->assertNull(Verification::find($this->b->id)->note_auto);

        // Une ligne « Doublon suspect » résiduelle est obsolète pour nettoyer().
        $this->assertTrue(app(DoublonSuspectService::class)->estNonDoublon('abc-de-non-doublon'));
        $stale = "Doublon suspect (Fonds vert P113 2025 ligne 11314800) : « ABC de Non Doublon » semble financer ce projet, mais d'autres projets portent le même nom.";
        $this->assertNull(app(DoublonSuspectService::class)->nettoyer($stale));
    }

    public function test_moderateur_et_contributeur_ne_peuvent_pas_trancher(): void
    {
        $moderateur = User::factory()->create();
        $moderateur->assignRole('moderateur');
        $contributeur = User::factory()->create();
        $contributeur->assignRole('contributeur');

        $this->actingAs($moderateur)
            ->postJson("/api/admin/projets/{$this->a->id}/non-doublon", [])
            ->assertForbidden();
        $this->actingAs($contributeur)
            ->postJson("/api/admin/projets/{$this->a->id}/non-doublon", [])
            ->assertForbidden();

        // Rien n'a bougé : la note est toujours là, pas de décision.
        $this->assertStringContainsString('Doublon suspect', (string) Verification::find($this->a->id)->note_auto);
        $this->assertSame(0, DoublonDecision::count());
    }

    public function test_nom_generique_refuse(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $generique = Projet::create([
            'id' => 'atlas-generique-a',
            'nom' => 'Atlas de la biodiversité communale',
            'slug' => 'atlas-de-la-biodiversite-communale-a',
            'statut' => 'en_cours',
            'source' => 'data.gouv',
        ]);

        $this->actingAs($admin)
            ->postJson("/api/admin/projets/{$generique->id}/non-doublon", [])
            ->assertStatus(400)
            ->assertJsonPath('error', "« Atlas de la biodiversité communale » est un nom générique porté par des dizaines de communes : l'homonymie y est la norme, rien à trancher.");

        $this->assertSame(0, DoublonDecision::count());
    }
}
