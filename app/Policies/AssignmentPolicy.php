<?php

namespace App\Policies;

use App\Models\Assignment;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Les devoirs se gèrent comme l'organisation (administrateurs, responsables) ;
 * les membres voient les devoirs publiés.
 */
class AssignmentPolicy
{
    public function view(User $user, Assignment $assignment): bool
    {
        return $this->manage($user, $assignment)
            || ($assignment->isPublished() && $assignment->organization->members()->whereKey($user->id)->exists());
    }

    public function manage(User $user, Assignment $assignment): bool
    {
        return Gate::forUser($user)->allows('update', $assignment->organization);
    }
}
