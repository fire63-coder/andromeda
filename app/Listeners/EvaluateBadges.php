<?php

namespace App\Listeners;

use App\Events\SubmissionEvaluated;
use App\Services\Gamification\BadgeEvaluator;

/**
 * Synchrone volontairement : l'élève voit son badge dans la foulée de sa soumission.
 */
class EvaluateBadges
{
    public function __construct(private readonly BadgeEvaluator $badges) {}

    public function handle(SubmissionEvaluated $event): void
    {
        $event->unlockedBadges = $this->badges->evaluate($event->submission->user);
    }
}
