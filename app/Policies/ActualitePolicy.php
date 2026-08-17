<?php

namespace App\Policies;

use App\Models\Actualite;
use App\Models\User;

class ActualitePolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function create(User $user): bool
    {
        return $user->hasRole(['admin', 'moderateur']);
    }

    public function update(User $user, Actualite $actualite): bool
    {
        return $user->hasRole(['admin', 'moderateur']);
    }

    public function delete(User $user, Actualite $actualite): bool
    {
        return $user->hasRole(['admin', 'moderateur']);
    }
}
