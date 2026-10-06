<?php

namespace App\Policies;

use App\Models\Dataset;
use App\Models\User;

class DatasetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAuthorContent();
    }

    public function view(User $user, Dataset $dataset): bool
    {
        return $user->canAuthorContent();
    }

    public function create(User $user): bool
    {
        return $user->canAuthorContent();
    }

    /** Lier / délier des exercices. */
    public function update(User $user, Dataset $dataset): bool
    {
        return $user->isAdmin() || $dataset->created_by === $user->id;
    }

    public function delete(User $user, Dataset $dataset): bool
    {
        return $user->isAdmin();
    }
}
