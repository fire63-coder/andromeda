<?php

namespace App\Contracts;

use App\Exceptions\ContextClosed;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\UserSubmission;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Épreuve dans laquelle un exercice est joué (tentative de certification, participation à un défi).
 * Le composant ExercisePlayer s'appuie dessus pour l'accès, le moteur imposé et l'enregistrement.
 */
interface ExerciseContext
{
    /** L'exercice fait-il partie de l'épreuve, encore ouverte ? */
    public function includes(Exercise $exercise): bool;

    /** Dialecte imposé par l'épreuve, le cas échéant. */
    public function imposedDialectId(): ?int;

    /**
     * @param  list<int>  $choiceIds
     *
     * @throws ContextClosed
     */
    public function recordAnswer(Exercise $exercise, SqlDialect $dialect, ?string $sql, array $choiceIds = []): UserSubmission;

    /**
     * @return MorphMany<UserSubmission, Model>
     */
    public function submissions(): MorphMany;
}
