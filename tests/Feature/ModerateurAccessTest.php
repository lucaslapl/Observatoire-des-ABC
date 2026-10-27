<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
                ->has('meta'));

        // Les sections de modération lui sont ouvertes…
        $this->actingAs($this->moderateur)->get('/admin/contributions')->assertOk();
        $this->actingAs($this->moderateur)->get('/admin/journaux')->assertOk();

        // …mais pas la maintenance du site (comptes = RGPD, paramétrage).
        $this->actingAs($this->moderateur)->get('/admin/comptes')->assertForbidden();
        $this->actingAs($this->moderateur)->get('/admin/actualites')->assertForbidden();
        $this->actingAs($this->moderateur)->get('/admin/exclusions')->assertForbidden();
        $this->actingAs($this->moderateur)->get('/admin/maintenance')->assertForbidden();
    }

    public function test_admin_voit_le_panneau_complet(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('isAdmin', true)
                ->has('meta'));

        $this->actingAs($admin)->get('/admin/contributions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('contributions')
                ->has('counts')
                ->where('statut', 'en_attente'));

        $this->actingAs($admin)->get('/admin/journaux')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('journalVerifications')
                ->has('journalFusions'));

        $this->actingAs($admin)->get('/admin/comptes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('comptes'));

        $this->actingAs($admin)->get('/admin/actualites')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('actualites'));

        $this->actingAs($admin)->get('/admin/exclusions')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('exclusions'));
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

    public function test_un_verdict_direct_est_journalise_avec_son_auteur(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_termine',
                'note' => 'Terrain terminé en 2023',
            ])
            ->assertOk();

        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'confirme_termine',
            'verifie_par' => $this->moderateur->name,
        ]);

        $this->assertDatabaseHas('audit_log', [
            'action' => 'verification',
            'par_admin' => $this->moderateur->name,
        ]);

        // Un second verdict sur le même projet : l'entrée d'audit trace
        // l'état précédent, pour savoir qui a corrigé quoi.
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_en_cours',
            ])
            ->assertOk();

        $entries = AuditLog::where('action', 'verification')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertNull($entries[0]->avant);
        $apres = json_decode($entries[0]->apres, true);
        $this->assertSame($this->projetId, $apres['projet_id']);
        $this->assertSame('ABC Modo Ville', $apres['nom']);
        $this->assertSame('confirme_termine', $apres['etat']);
        $this->assertSame('confirme_termine', json_decode($entries[1]->avant, true)['etat']);
        $this->assertSame('confirme_en_cours', json_decode($entries[1]->apres, true)['etat']);

        // Le panneau admin expose ce journal aux modérateurs comme aux admins.
        $this->actingAs($this->moderateur)->get('/admin/journaux')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('journalVerifications.data', 2)
                ->where('journalVerifications.data.0.par', $this->moderateur->name)
                ->where('journalVerifications.data.0.etat', 'confirme_en_cours')
                ->where('journalVerifications.data.0.etat_avant', 'confirme_termine'));
    }

    public function test_verdict_de_date_avec_statut_constate_corrige_aussi_le_statut(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_date',
                'annee_debut' => 2023,
                'statut_suggere' => 'en_cours',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'etat' => 'confirme_en_cours']);

        // Le verdict enregistré est celui du statut constaté : la carte et la
        // fiche affichent ce statut via statutDepuisVerification.
        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'confirme_en_cours',
        ]);
        $this->assertDatabaseHas('projets', [
            'id' => $this->projetId,
            'annee_debut' => 2023,
            'annee_fin' => null,
        ]);

        // Le statut constaté est tracé dans le journal d'audit.
        $apres = json_decode(AuditLog::where('action', 'verification')->latest('id')->value('apres'), true);
        $this->assertSame('en_cours', $apres['statut_constate']);
        $this->assertSame('confirme_en_cours', $apres['etat']);
        $this->assertSame(2023, $apres['annee_debut']);
    }

    public function test_verdict_de_date_sans_statut_constate_garde_le_statut_calcule(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_date',
                // L'année de début seule doit suffire, comme promis dans l'aide.
                'annee_debut' => 2022,
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'etat' => 'confirme_date']);

        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'confirme_date',
        ]);
        $this->assertDatabaseHas('projets', [
            'id' => $this->projetId,
            'annee_debut' => 2022,
            'annee_fin' => null,
        ]);
    }

    public function test_verdict_de_date_porte_la_ligne_dates_confirmees(): void
    {
        // Comme une contribution validée, un verdict de correction de date
        // écrit la ligne « Dates confirmées : … » dans la note : affichage
        // homogène sur la fiche et protection contre l'écrasement par le
        // collect (ProjetRepository). Une ligne antérieure est remplacée.
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_date',
                'annee_debut' => 2026,
                'note' => 'Communiqué de la ville',
            ])
            ->assertOk();

        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'note' => "Communiqué de la ville\n— Dates confirmées : 2026",
        ]);

        // Second verdict avec statut constaté : le verdict de statut remplace
        // « confirme_date », la ligne de dates est remplacée sans empiler.
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_date',
                'annee_debut' => 2027,
                'annee_fin' => 2030,
                'statut_suggere' => 'va_debuter',
                'note' => 'Communiqué de la ville',
            ])
            ->assertOk()
            ->assertJson(['ok' => true, 'etat' => 'toujours_a_venir']);

        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'etat' => 'toujours_a_venir',
            'note' => "Communiqué de la ville\n— Dates confirmées : 2027–2030",
        ]);
    }

    public function test_statut_constate_invalide_est_refuse(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/api/verifications', [
                'projet_id' => $this->projetId,
                'etat' => 'confirme_date',
                'statut_suggere' => 'peut_etre',
            ])
            ->assertInvalid(['statut_suggere']);

        $this->assertDatabaseMissing('verifications', ['projet_id' => $this->projetId]);
    }

    public function test_moderateur_suggestion_is_applied_immediately(): void
    {
        $this->actingAs($this->moderateur)
            ->postJson('/api/contributions', [
                'projet_id' => $this->projetId,
                'type' => 'note',
                'note' => 'Note experte sourcée',
                'commentaire' => 'Source : bulletin communal 2024',
            ])
            ->assertCreated()
            ->assertJson(['ok' => true, 'auto_validee' => true]);

        $this->assertDatabaseHas('contributions', [
            'projet_id' => $this->projetId,
            'user_id' => $this->moderateur->id,
            'statut' => 'validee',
            'traite_par' => $this->moderateur->name,
        ]);

        $this->assertDatabaseHas('verifications', [
            'projet_id' => $this->projetId,
            'note' => 'Note experte sourcée',
            'verifie_par' => $this->moderateur->name,
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

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_admin_peut_promouvoir_un_moderateur(): void
    {
        $user = User::factory()->create();
        $user->assignRole('contributeur');

        $this->actingAs($this->createAdmin())
            ->postJson("/api/admin/comptes/{$user->id}/moderateur")
            ->assertOk()
            ->assertJson(['ok' => true, 'roles' => ['contributeur', 'moderateur']]);

        $this->assertTrue($user->refresh()->hasRole('moderateur'));
    }

    public function test_admin_peut_retirer_le_role_moderateur(): void
    {
        $this->actingAs($this->createAdmin())
            ->deleteJson("/api/admin/comptes/{$this->moderateur->id}/moderateur")
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertFalse($this->moderateur->refresh()->hasRole('moderateur'));
    }

    public function test_moderateur_ne_peut_pas_gerer_les_roles(): void
    {
        $user = User::factory()->create();
        $user->assignRole('contributeur');

        $this->actingAs($this->moderateur)
            ->postJson("/api/admin/comptes/{$user->id}/moderateur")
            ->assertForbidden();

        $this->assertFalse($user->refresh()->hasRole('moderateur'));
    }

    public function test_un_compte_non_verifie_ne_peut_pas_etre_promu(): void
    {
        $unverified = User::factory()->unverified()->create();
        $unverified->assignRole('contributeur');

        $this->actingAs($this->createAdmin())
            ->postJson("/api/admin/comptes/{$unverified->id}/moderateur")
            ->assertStatus(400);

        $this->assertFalse($unverified->refresh()->hasRole('moderateur'));
    }

    public function test_le_role_admin_ne_peut_pas_etre_modifie_depuis_le_panneau(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/comptes/{$admin->id}/moderateur")
            ->assertStatus(400);

        $this->assertTrue($admin->refresh()->hasRole('admin'));
    }
}
