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
}
