<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Projet;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfilTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->user = User::create([
            'name' => 'Marie Martin',
            'email' => 'marie@example.com',
            'email_verified_at' => now(),
            'password' => 'mot-de-passe-tres-long',
        ]);
        $this->user->assignRole('contributeur');

        foreach (['abc-x', 'abc-y'] as $id) {
            Projet::create([
                'id' => $id,
                'nom' => "Projet {$id}",
                'structure_porteuse' => 'Structure Test',
                'annee_debut' => 2021,
                'statut' => 'en_cours',
                'source' => 'datagouv',
            ]);
        }
    }

    public function test_profil_shows_own_contributions(): void
    {
        Contribution::create([
            'projet_id' => 'abc-x',
            'user_id' => $this->user->id,
            'type' => 'note',
            'payload_json' => ['note' => 'Note de Marie'],
            'statut' => 'en_attente',
        ]);

        $response = $this->actingAs($this->user)->get('/profil');

        $response->assertOk();
        $this->assertStringContainsString('marie@example.com', $response->getContent());
    }

    public function test_profil_is_reserved_to_authenticated_users(): void
    {
        $this->get('/profil')->assertRedirect('/login');
    }

    public function test_password_update_requires_current_password(): void
    {
        $this->actingAs($this->user)
            ->put('/profil/password', [
                'current_password' => 'mauvais-mot-de-passe',
                'password' => 'nouveau-mot-de-passe-long',
                'password_confirmation' => 'nouveau-mot-de-passe-long',
            ])
            ->assertInvalid('current_password');

        $this->assertTrue(Hash::check('mot-de-passe-tres-long', $this->user->fresh()->password));

        $this->actingAs($this->user)
            ->put('/profil/password', [
                'current_password' => 'mot-de-passe-tres-long',
                'password' => 'nouveau-mot-de-passe-long',
                'password_confirmation' => 'nouveau-mot-de-passe-long',
            ])
            ->assertRedirect();

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-long', $this->user->fresh()->password));
    }

    public function test_account_deletion_keeps_contributions_anonymized(): void
    {
        Contribution::create([
            'projet_id' => 'abc-y',
            'user_id' => $this->user->id,
            'type' => 'lien',
            'payload_json' => ['lien' => 'https://example.com/abc'],
            'statut' => 'validee',
        ]);

        $this->actingAs($this->user)
            ->delete('/profil', ['current_password' => 'mauvais-mot-de-passe'])
            ->assertInvalid('current_password');

        $this->actingAs($this->user)
            ->delete('/profil', ['current_password' => 'mot-de-passe-tres-long'])
            ->assertRedirect('/');

        $this->assertNull(User::find($this->user->id));

        $contribution = Contribution::query()->where('projet_id', 'abc-y')->first();
        $this->assertNotNull($contribution, 'La contribution (travail humain) doit être conservée.');
        $this->assertNull($contribution->user_id);
        $this->assertGuest();
    }
}
