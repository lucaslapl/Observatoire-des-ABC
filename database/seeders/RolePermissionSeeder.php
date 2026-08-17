<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['admin', 'moderateur', 'contributeur'] as $role) {
            Role::findOrCreate($role);
        }
    }
}
