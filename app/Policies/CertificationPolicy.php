<?php

namespace App\Policies;

use App\Models\Certification;
use App\Models\User;

/**
 * Épreuves à enjeu (certificats, classements) : gérées par les administrateurs uniquement.
 */
class CertificationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Certification $model): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, Certification $model): bool
    {
        return $user->isAdmin();
    }
}
