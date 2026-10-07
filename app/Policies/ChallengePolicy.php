<?php

namespace App\Policies;

use App\Models\Challenge;
use App\Models\User;

/**
 * Épreuves à enjeu (certificats, classements) : gérées par les administrateurs uniquement.
 */
class ChallengePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Challenge $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Challenge $model): bool
    {
        return $user->isAdmin();
    }
}
