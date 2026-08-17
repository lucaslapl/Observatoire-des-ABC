<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Crée le compte administrateur depuis l'environnement (ADMIN_*).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $username = config('abc.admin.username', 'admin');
        $email = config('abc.admin.email');
        $password = config('abc.admin.password');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL/ADMIN_PASSWORD absents : compte admin non créé.');

            return;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $username,
                'email' => $email,
                'password' => $this->hashIfNeeded($password),
            ],
        );

        $role = Role::findOrCreate('admin');
        $user->assignRole($role);

        $this->command?->info("Admin créé : {$email} (rôle admin)");
    }

    private function hashIfNeeded(string $password): string
    {
        if (str_starts_with($password, '$2y$')
            || str_starts_with($password, '$2a$')
            || str_starts_with($password, '$argon2')) {
            return $password;
        }

        return Hash::make($password);
    }
}
