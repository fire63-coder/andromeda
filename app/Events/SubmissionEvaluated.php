<?php

namespace App\Events;

use App\Models\Badge;
use App\Models\Rank;
use App\Models\UserSubmission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Émis après l'enregistrement d'une soumission : point d'accroche des badges,
 * classements, notifications...
 */
class SubmissionEvaluated
{
    use Dispatchable;
    use SerializesModels;

    /**
     * Badges obtenus grâce à cette soumission (renseigné par le listener EvaluateBadges).
     *
     * @var Collection<int, Badge>
     */
    public Collection $unlockedBadges;

    public function __construct(
        public UserSubmission $submission,
        public bool $firstSolve,
        public ?Rank $promotedTo = null,
    ) {
        $this->unlockedBadges = new Collection;
    }
}
