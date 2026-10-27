<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use App\Models\Verification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ContributionFlowTest extends TestCase
{
    use RefreshDatabase;

    private string $projetId = 'abc-test-ville';

    private User $contributeur;

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

        $this->contributeur = User::factory()->create();
        $this->contributeur->assignRole('contributeur');
    }

    private function postContribution(array $body)
    {
        return $this->actingAs($this->contributeur)->postJson('/api/contributions', $body);
    }

    public function test_admin_contribution_is_applied_immediately(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'date_debut',
            'annee_debut_suggeree' => 2023,
            'annee_fin_suggeree' => 2026,
            'source' => 'https://exemple.fr/abc-test-ville',
        ]);

        $response->assertCreated()->assertJson(['ok' => true, 'auto_validee' => true]);

        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->projetId,
            'user_id' => $admin->id,
            'statut' => 'validee',
            'traite_par' => $admin->name,
        ]);
        $this->assertDatabaseHas('projets', [
            'id' => $this->projetId,
            'annee_debut' => 2023,
            'annee_fin' => 2026,
        ]);
        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'confirme_date',
            'verifie_par' => $admin->name,
        ]);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'validee',
            'par_admin' => $admin->name,
        ]);
    }

    public function test_admin_date_contribution_with_statut_sets_verdict_statut(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        // ABC débuté en cours d'année : le statut source reste « a_venir » et
        // le recalcul par année l'afficherait « va débuter » jusqu'en 2027.
        // Le contributeur précise « en cours » en même temps que la date.
        $this->actingAs($admin)->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'date_debut',
            'annee_debut_suggeree' => 2026,
            'statut_suggere' => 'en_cours',
            'source' => 'https://exemple.fr/abc-test-ville',
        ])->assertCreated()->assertJson(['ok' => true, 'auto_validee' => true]);

        $this->assertDatabaseHas('projets', [
            'id' => $this->projetId,
            'annee_debut' => 2026,
        ]);
        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'confirme_en_cours',
        ]);
    }

    public function test_date_contribution_remplace_la_ligne_dates_confirmees(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        Verification::create([
            'projet_id' => $this->projetId,
            'etat' => 'a_verifier',
            'note' => 'Vu sur le site officiel',
        ]);

        $send = fn (array $extra) => $this->actingAs($admin)->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'date_debut',
        ] + $extra)->assertCreated();

        $send(['annee_debut_suggeree' => 2026, 'source' => 'https://exemple.fr/article-1']);
        $send(['annee_debut_suggeree' => 2026, 'annee_fin_suggeree' => 2029, 'statut_suggere' => 'en_cours', 'source' => 'https://exemple.fr/article-2']);

        $verification = Verification::where('projet_id', $this->projetId)->first();
        $this->assertSame('confirme_en_cours', $verification->etat);
        $this->assertSame(1, substr_count((string) $verification->note, 'Dates confirmées'));
        $this->assertSame(1, substr_count((string) $verification->note, 'Source : https'));
        $this->assertStringContainsString('Vu sur le site officiel', (string) $verification->note);
        $this->assertStringContainsString('Dates confirmées : 2026–2029', (string) $verification->note);
        $this->assertStringContainsString('https://exemple.fr/article-2', (string) $verification->note);
    }

    public function test_contributeur_contribution_stays_pending(): void
    {
        $this->postContribution([
            'projet_id' => $this->projetId,
            'type' => 'note',
            'note' => 'bonjour',
        ])->assertCreated()->assertJson(['ok' => true, 'auto_validee' => false]);

        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->projetId,
            'statut' => 'en_attente',
        ]);
        $this->assertDatabaseMissing('verifications', [
            'projet_id' => $this->projetId,
        ]);
    }

    public function test_guest_cannot_contribute(): void
    {
        $this->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'note',
            'note' => 'bonjour',
        ])->assertUnauthorized();

        $this->assertDatabaseCount('contributions', 0);
    }

    public function test_unverified_user_cannot_contribute(): void
    {
        $unverified = User::factory()->unverified()->create();
        $unverified->assignRole('contributeur');

        $this->actingAs($unverified)->postJson('/api/contributions', [
            'projet_id' => $this->projetId,
            'type' => 'note',
            'note' => 'bonjour',
        ])->assertForbidden();

        $this->assertDatabaseCount('contributions', 0);
    }

    public function test_verified_user_contribution_is_created(): void
    {
        $response = $this->postContribution([
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
        $this->postContribution([
            'projet_id' => $this->projetId,
            'type' => 'bidon',
        ])->assertStatus(422);
    }

    public function test_public_contributions_list_only_shows_validated(): void
    {
        Contribution::create([
            'projet_id' => $this->projetId,
            'type' => 'statut',
            'payload_json' => ['statut_suggere' => 'termine'],
            'statut' => 'en_attente',
        ]);
        Contribution::create([
            'projet_id' => $this->projetId,
            'type' => 'note',
            'payload_json' => ['note' => 'Note refusée'],
            'statut' => 'refusee',
        ]);
        Contribution::create([
            'projet_id' => $this->projetId,
            'type' => 'note',
            'payload_json' => ['note' => 'Note validée'],
            'statut' => 'validee',
        ]);

        $response = $this->getJson('/api/contributions');
        $response->assertOk();

        $rows = $response->json('contributions');
        $this->assertCount(1, $rows);
        $this->assertSame('validee', $rows[0]['statut']);
        $this->assertSame('Note validée', $rows[0]['payload_json']['note']);
    }

    public function test_contribution_unknown_projet_returns_404(): void
    {
        $this->postContribution([
            'projet_id' => 'n-existe-pas',
            'type' => 'note',
            'note' => 'bonjour',
        ])->assertNotFound();
    }

    public function test_contribution_payload_keeps_string_values(): void
    {
        $this->postContribution([
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

        $this->postContribution([
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

        $this->actingAs($contributor)->postJson('/api/contributions', [
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

    public function test_admin_can_reject_contribution(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->postContribution([
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

    public function test_admin_can_annul_contribution(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->postContribution([
            'projet_id' => $this->projetId,
            'type' => 'note',
            'note' => 'envoyé par erreur',
        ])->assertCreated();
        $id = Contribution::first()->id;

        $this->actingAs($admin)
            ->postJson("/api/admin/contributions/{$id}/annuler")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('contributions', ['id' => $id, 'statut' => 'annulee']);
        $this->assertDatabaseHas('audit_log', ['contribution_id' => $id, 'action' => 'annulee']);
    }

    public function test_authenticated_contribution_is_linked_to_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole('contributeur');

        $this->actingAs($user)
            ->postJson('/api/contributions', [
                'projet_id' => $this->projetId,
                'type' => 'note',
                'note' => 'note identifiée',
            ])->assertCreated();

        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->projetId,
            'user_id' => $user->id,
        ]);
    }

    public function test_contribution_rate_limited(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postContribution([
                'projet_id' => $this->projetId,
                'type' => 'note',
                'note' => "note {$i}",
            ])->assertCreated();
        }

        $this->postContribution([
            'projet_id' => $this->projetId,
            'type' => 'note',
            'note' => 'trop',
        ])->assertStatus(429);
    }

    public function test_admin_page_lists_all_comptes(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $response = $this->actingAs($admin)->get('/admin/comptes');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('comptes.data', 2));

        $emails = array_column($response->inertiaProps('comptes')['data'], 'email');
        $this->assertContains($this->contributeur->email, $emails);
        $this->assertContains($admin->email, $emails);
    }

    public function test_contributions_sont_paginees_cote_serveur(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        for ($i = 0; $i < 25; $i++) {
            Contribution::create([
                'projet_id' => $this->projetId,
                'type' => 'note',
                'payload_json' => ['note' => "note $i"],
                'statut' => 'en_attente',
            ]);
        }

        $page1 = $this->actingAs($admin)->get('/admin/contributions');
        $page1->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions.data', 20)
                ->where('contributions.last_page', 2)
                ->where('contributions.total', 25)
                ->where('counts.en_attente', 25));

        $page2 = $this->actingAs($admin)->get('/admin/contributions?page=2');
        $page2->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions.data', 5)
                ->where('contributions.current_page', 2));
    }
}
