<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RememberMeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        User::create([
            'name' => 'Marie Martin',
            'email' => 'marie@example.com',
            'email_verified_at' => now(),
            'password' => 'mot-de-passe-tres-long',
        ]);
    }

    public function test_login_with_remember_sets_remember_cookie(): void
    {
        $response = $this->post('/login', [
            'email' => 'marie@example.com',
            'password' => 'mot-de-passe-tres-long',
            'remember' => '1',
        ]);

        $response->assertRedirect('/profil');
        $this->assertTrue(
            collect($response->headers->getCookies())
                ->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')),
            'La case « se souvenir de moi » doit poser un cookie remember_web_….'
        );
    }

    public function test_login_without_remember_does_not_set_remember_cookie(): void
    {
        $response = $this->post('/login', [
            'email' => 'marie@example.com',
            'password' => 'mot-de-passe-tres-long',
        ]);

        $response->assertRedirect('/profil');
        $this->assertFalse(
            collect($response->headers->getCookies())
                ->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_')),
            'Sans la case cochée, aucun cookie remember_web_… ne doit être posé.'
        );
    }
}
