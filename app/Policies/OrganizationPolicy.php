<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

/**
 * Administrateurs : toutes les organisations. Responsables (pivot role = manager) : les leurs.
 */
class OrganizationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->organizations()->wherePivot('role', 'manager')->exists();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->isAdmin()
            || $organization->members()->whereKey($user->id)->wherePivot('role', 'manager')->exists();
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->isAdmin();
    }
}
