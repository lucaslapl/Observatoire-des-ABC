<?php

namespace App\Policies;

use App\Models\Contribution;
use App\Models\User;

class ContributionPolicy
{
    public function before(User $user, string $ability): ?bool
    {
        if ($user->hasRole('admin')) {
            return true;
        }

        return null;
    }

    public function moderer(User $user, Contribution $contribution): bool
    {
        return $user->hasRole(['moderateur', 'admin']);
    }
}
