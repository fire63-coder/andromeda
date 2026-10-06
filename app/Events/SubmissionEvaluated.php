<?php

namespace App\Events;

use App\Models\Rank;
use App\Models\UserSubmission;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Émis après l'enregistrement d'une soumission : point d'accroche des badges,
 * classements, notifications...
 */
class SubmissionEvaluated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public UserSubmission $submission,
        public bool $firstSolve,
        public ?Rank $promotedTo = null,
    ) {}
}
