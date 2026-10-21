<?php

namespace Tests\Feature;

use App\Models\Projet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Page /verify : la liste publique est accessible à tous, mais le contexte
 * d'authentification (rôle + email vérifié) détermine ce que le front affiche :
 * rien pour un visiteur, un formulaire de suggestion pour un contributeur
 * vérifié, le verdict direct pour un admin.
 */
class VerifyPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        Projet::create([
            'id' => 'abc-verify-x',
            'nom' => 'ABC Verify Ville',
            'structure_porteuse' => 'Ville Verify',
            'annee_debut' => 2021,
            'statut' => 'en_cours',
            'source' => 'datagouv',
            'potentiellement_termine' => true,
        ]);
    }

    public function test_invite_voit_la_liste_sans_compte(): void
    {
        $this->get('/verify')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user', null)
                ->where('projets.0.id', 'abc-verify-x')
                ->where('projets.0.etat', 'a_verifier')
                ->where('projets.0.motifs.0', 'potentiellement terminé')
                ->has('compteurs'));
    }

    public function test_contributeur_verifie_recupere_son_contexte(): void
    {
        $user = User::factory()->create();
        $user->assignRole('contributeur');

        $this->actingAs($user)->get('/verify')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.id', $user->id)
                ->where('auth.user.email_verifie', true)
                ->where('auth.user.roles.0', 'contributeur'));
    }

    public function test_admin_recupere_son_contexte(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->get('/verify')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('auth.user.roles.0', 'admin'));
    }
}
