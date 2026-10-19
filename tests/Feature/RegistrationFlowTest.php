<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Notification::fake();
    }

    public function test_registration_creates_contributor_and_sends_verification_email(): void
    {
        $response = $this->post('/register', [
            'name' => 'Jeanne Dupont',
            'email' => 'jeanne@example.com',
            'password' => 'mot-de-passe-tres-long',
            'password_confirmation' => 'mot-de-passe-tres-long',
        ]);

        $response->assertRedirect('/verification-email');

        $user = User::where('email', 'jeanne@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole('contributeur'));
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check('mot-de-passe-tres-long', $user->password));

        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertAuthenticatedAs($user);
    }

    public function test_registration_rejects_short_password_and_duplicate_email(): void
    {
        $this->post('/register', [
            'name' => 'Court',
            'email' => 'court@example.com',
            'password' => 'tropcourt',
            'password_confirmation' => 'tropcourt',
        ])->assertInvalid('password');

        User::create([
            'name' => 'Existant',
            'email' => 'dup@example.com',
            'password' => 'mot-de-passe-tres-long',
        ]);

        $this->post('/register', [
            'name' => 'Dup',
            'email' => 'dup@example.com',
            'password' => 'mot-de-passe-tres-long',
            'password_confirmation' => 'mot-de-passe-tres-long',
        ])->assertInvalid('email');
    }

    public function test_register_is_rate_limited_by_ip(): void
    {
        $payload = [
            'name' => 'Flood',
            'password' => 'mot-de-passe-tres-long',
            'password_confirmation' => 'mot-de-passe-tres-long',
        ];

        // 10 emails distincts depuis la même IP : la limite par IP (10/h)
        // du limiter « register » doit bloquer la 11e tentative.
        for ($i = 1; $i <= 10; $i++) {
            $this->post('/register', [...$payload, 'email' => "flood{$i}@example.com"])
                ->assertRedirect('/verification-email');

            // Le middleware guest redirige un utilisateur connecté : on se
            // déconnecte entre chaque inscription.
            $this->post('/logout');
        }

        $this->post('/register', [...$payload, 'email' => 'flood11@example.com'])
            ->assertStatus(429);
    }

    public function test_unverified_user_is_redirected_to_verification_notice(): void
    {
        $user = User::create([
            'name' => 'En attente',
            'email' => 'attente@example.com',
            'password' => 'mot-de-passe-tres-long',
        ]);

        $this->actingAs($user)
            ->get('/profil')
            ->assertRedirect('/verification-email');
    }

    public function test_email_verification_link_grants_access_to_profil(): void
    {
        $user = User::create([
            'name' => 'À vérifier',
            'email' => 'verif@example.com',
            'password' => 'mot-de-passe-tres-long',
        ]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect('/profil');

        $this->assertNotNull($user->fresh()->email_verified_at);

        $this->actingAs($user)->get('/profil')->assertOk();
    }

    public function test_resend_verification_email_is_throttled(): void
    {
        $user = User::create([
            'name' => 'Renvoi',
            'email' => 'renvoi@example.com',
            'password' => 'mot-de-passe-tres-long',
        ]);

        $this->actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->post('/verification-email/send')->assertRedirect();
        }

        $this->post('/verification-email/send')->assertStatus(429);
    }

    public function test_password_reset_link_and_new_password(): void
    {
        $user = User::create([
            'name' => 'Oubli',
            'email' => 'oubli@example.com',
            'password' => 'mot-de-passe-tres-long',
        ]);

        $this->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect()
            ->assertSessionHas('status');

        $token = null;
        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function ($notification) use (&$token) {
                $token = $notification->token;

                return true;
            }
        );
        $this->assertNotNull($token);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nouveau-mot-de-passe-long',
            'password_confirmation' => 'nouveau-mot-de-passe-long',
        ])->assertRedirect('/profil');

        $this->assertTrue(Hash::check('nouveau-mot-de-passe-long', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    public function test_password_reset_with_invalid_token_is_rejected(): void
    {
        $user = User::create([
            'name' => 'Jetons',
            'email' => 'jetons@example.com',
            'password' => 'mot-de-passe-tres-long',
        ]);

        $this->post('/reset-password', [
            'token' => 'jeton-invalide',
            'email' => $user->email,
            'password' => 'nouveau-mot-de-passe-long',
            'password_confirmation' => 'nouveau-mot-de-passe-long',
        ])->assertInvalid('email');

        $this->assertTrue(Hash::check('mot-de-passe-tres-long', $user->fresh()->password));
    }
}
