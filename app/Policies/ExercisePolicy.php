<?php

namespace App\Policies;

use App\Enums\ContentStatus;
use App\Models\Exercise;
use App\Models\User;

class ExercisePolicy
{
    /**
     * Les exercices publiés sont ouverts à tous ; les brouillons à l'équipe pédagogique (aperçu).
     */
    public function view(User $user, Exercise $exercise): bool
    {
        return $exercise->status === ContentStatus::Published || $user->canAuthorContent();
    }
}
