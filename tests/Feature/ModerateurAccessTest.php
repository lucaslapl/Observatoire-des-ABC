<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Rôle « moderateur » : expert de confiance. Il modère les contributions,
 * enregistre des verdicts directs et envoie ses propres suggestions —
 * mais n'a pas accès à la maintenance (collect, sauvegardes, exclusions,
 * actualités, comptes).
 */
class ModerateurAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $projetId = 'abc-modo';

    private User $moderateur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Projet::create([
            'id' => $this->projetId,
            'nom' => 'ABC Modo Ville',
            'structure_porteuse' => 'Ville Modo',
            'annee_debut' => 2020,
            'statut' => 'en_cours',
            'source' => 'datagouv',
        ]);

        $this->moderateur = User::factory()->create();
        $this->moderateur->assignRole('moderateur');
    }

    private function createContribution(): int
    {
        $c = Contribution::create([
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'payload_json' => ['statut_suggere' => 'termine'],
            'statut' => 'en_attente',
        ]);

        return $c->id;
    }

    public function test_moderateur_voit_le_panneau_en_mode_moderation(): void
    {
        $this->actingAs($this->moderateur)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isAdmin', false)
                ->has('contributions')
                ->has('meta')
                ->missing('comptes')
                ->missing('actualites')
                ->missing('exclusions'));
    }

    public function test_admin_voit_le_panneau_complet(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isAdmin', true)
                ->has('contributions')
                ->has('comptes')
                ->has('actualites')
                ->has('exclusions'));
    }

    public function test_contributeur_n_accede_pas_au_panneau(): void
    {
        $user = User::factory()->create();
        $user->assignRole('contributeur');

        $this->actingAs($user)->get('/admin')->assertForbidden();
    }

    public function test_moderateur_peut_valider_une_contribution(): void
    {
        $id = $this->createContribution();

        $this->actingAs($this->moderateur)
            ->postJson("/api/admin/contributions/{$id}/valider")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('contributions', [
            'id' => $id,
            'statut' => 'validee',
            'traite_par' => $this->moderateur->name,
        ]);
        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'confirme_termine',
        ]);
    }

    public function test_moderateur_peut_refuser_une_contribution(): void
    {
        $id = $this->createContribution();

        $this->actingAs($this->moderateur)
            ->postJson("/api/admin/contributions/{$id}/refuser", ['note_admin' => 'source insuffisante'])
            ->assertOk();

        $this->assertDatabaseHas('contributions', [
            'id' => $id,
            'statut' => 'refusee',
            'note_admin' => 'source insuffisante',
        ]);
    }

    public function test_moderateur_peut_enregistrer_un_verdict_direct(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_termine',
                'note' => 'Terrain terminé en 2023',
                'lien' => 'https://example.org/abc-modo',
            ])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'confirme_termine',
            'lien' => 'https://example.org/abc-modo',
        ]);
    }

    public function test_moderateur_peut_envoyer_sa_propre_suggestion(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/api/contributions', [
                'projet_id' => $this->projetId,
                'type' => 'note',
                'note' => 'Note experte sourcée',
                'commentaire' => 'Source : bulletin communal 2024',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->projetId,
            'user_id' => $this->moderateur->id,
            'statut' => 'en_attente',
        ]);
    }

    public function test_moderateur_ne_peut_pas_lancer_la_maintenance(): void
    {
        $this->actingAs($this->moderateur)->postJson('/api/admin/collect')->assertForbidden();
        $this->actingAs($this->moderateur)->postJson('/api/admin/backup')->assertForbidden();
        $this->actingAs($this->moderateur)->getJson('/api/admin/exclusions')->assertForbidden();
        $this->actingAs($this->moderateur)
            ->deleteJson('/api/admin/projets/'.urlencode($this->projetId))
            ->assertForbidden();

        $this->assertDatabaseHas('projets', ['id' => $this->projetId]);
    }

    public function test_moderateur_ne_peut_pas_gerer_les_actualites(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/actualites', [
                'titre' => 'Titre',
                'contenu' => 'Contenu',
                'statut' => 'publie',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('actualites', 0);
    }
}
