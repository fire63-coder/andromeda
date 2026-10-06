<?php

namespace App\Actions\Exercises;

use App\Models\Badge;
use App\Models\Rank;
use App\Models\UserSubmission;
use Illuminate\Support\Collection;

/**
 * Ce que l'élève doit voir après une soumission : verdict, XP, promotion, badges.
 */
final readonly class SubmissionOutcome
{
    /**
     * @param  Collection<int, Badge>  $unlockedBadges
     */
    public function __construct(
        public UserSubmission $submission,
        public bool $firstSolve,
        public ?Rank $promotedTo,
        public Collection $unlockedBadges,
    ) {}
}
