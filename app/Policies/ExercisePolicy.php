<?php

namespace App\Policies;

use App\Models\Exercise;
use App\Models\User;

class ExercisePolicy
{
    /**
     * Entraînement libre : exercices publiés rattachés à une leçon. Brouillons et exercices
     * réservés (certifications, défis) : équipe pédagogique uniquement (aperçu).
     */
    public function view(User $user, Exercise $exercise): bool
    {
        return $exercise->isPractice() || $user->canAuthorContent();
    }

    public function viewAny(User $user): bool
    {
        return $user->canAuthorContent();
    }

    public function create(User $user): bool
    {
        return $user->canAuthorContent();
    }

    /** Formateur : ses exercices ; administrateur : tous. */
    public function update(User $user, Exercise $exercise): bool
    {
        return $user->isAdmin() || ($user->canAuthorContent() && $exercise->author_id === $user->id);
    }

    /** Publication après relecture : administrateurs uniquement. */
    public function publish(User $user): bool
    {
        return $user->isAdmin();
    }
}
