<?php

namespace App\Policies;

use App\Models\User;

/**
 * Gestion des comptes (rôles, activation) : administrateurs uniquement.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function manage(User $user, User $target): bool
    {
        return $user->isAdmin();
    }
}
