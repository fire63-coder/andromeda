<?php

namespace App\Actions\Exercises;

use App\Enums\ProgressStatus;
use App\Enums\SubmissionStatus;
use App\Events\SubmissionEvaluated;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\User;
use App\Models\UserProgress;
use App\Services\Evaluation\EvaluationResult;
use App\Services\Evaluation\SubmissionEvaluator;
use App\Services\Gamification\StreakService;
use App\Services\Gamification\XpService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Évalue une réponse, l'enregistre et applique ses effets (XP, progression, série).
 */
class SubmitAnswer
{
    /** Part minimale de l'XP conservée quels que soient les indices consultés. */
    private const MIN_XP_RATIO = 0.2;

    public function __construct(
        private readonly SubmissionEvaluator $evaluator,
        private readonly XpService $xp,
        private readonly StreakService $streaks,
    ) {}

    /**
     * @param  list<int>  $choiceIds
     */
    public function handle(
        User $user,
        Exercise $exercise,
        SqlDialect $dialect,
        ?string $sql,
        array $choiceIds = [],
        int $hintsUsed = 0,
        ?Carbon $startedAt = null,
    ): SubmissionOutcome {
        $evaluation = $this->timeLimitExceeded($exercise, $startedAt)
            ? new EvaluationResult(SubmissionStatus::Timeout, 0, 'Temps écoulé : ce défi chronométré est terminé.')
            : $this->evaluator->evaluate($exercise, $dialect, $sql, $choiceIds);

        [$submission, $firstSolve, $promotedTo] = DB::transaction(function () use ($user, $exercise, $dialect, $sql, $choiceIds, $hintsUsed, $evaluation) {
            $alreadySolved = $user->submissions()
                ->where('exercise_id', $exercise->id)
                ->where('is_correct', true)
                ->lockForUpdate()
                ->exists();

            $firstSolve = $evaluation->isCorrect() && ! $alreadySolved;
            $xp = $firstSolve ? $this->xpFor($exercise, $hintsUsed) : 0;

            $submission = $user->submissions()->create([
                'exercise_id' => $exercise->id,
                'sql_dialect_id' => $dialect->id,
                'query_sql' => $sql,
                'selected_choice_ids' => $choiceIds ?: null,
                'status' => $evaluation->status,
                'is_correct' => $evaluation->isCorrect(),
                'score' => $evaluation->score,
                'execution_ms' => $evaluation->result?->durationMs,
                'rows_returned' => $evaluation->result?->hasResultSet() ? count($evaluation->result->rows) : null,
                'result_preview' => $evaluation->result?->toPreview(20),
                'feedback' => ['message' => $evaluation->message, ...$evaluation->feedback],
                'error_message' => $evaluation->status === SubmissionStatus::Error ? $evaluation->message : null,
                'hints_used' => $hintsUsed,
                'xp_awarded' => $xp,
            ]);

            $this->updateProgress($user, $exercise, $evaluation);
            $this->streaks->touch($user);

            $promotedTo = $this->xp->award($user, $xp, 'exercise_solved', $submission, ['exercise' => $exercise->slug]);

            return [$submission, $firstSolve, $promotedTo];
        });

        $event = new SubmissionEvaluated($submission, $firstSolve, $promotedTo);
        event($event);

        return new SubmissionOutcome($submission, $firstSolve, $promotedTo, $event->unlockedBadges);
    }

    public function xpFor(Exercise $exercise, int $hintsUsed): int
    {
        $penalty = collect($exercise->hints ?? [])
            ->take($hintsUsed)
            ->sum(fn (array $hint) => (int) ($hint['xp_penalty'] ?? 0));

        return max((int) ceil($exercise->xp_reward * self::MIN_XP_RATIO), $exercise->xp_reward - $penalty);
    }

    private function updateProgress(User $user, Exercise $exercise, EvaluationResult $evaluation): void
    {
        $progress = UserProgress::firstOrNew([
            'user_id' => $user->id,
            'progressable_type' => $exercise->getMorphClass(),
            'progressable_id' => $exercise->id,
        ]);

        $completed = $progress->status === ProgressStatus::Completed || $evaluation->isCorrect();

        $progress->fill([
            'status' => $completed ? ProgressStatus::Completed : ProgressStatus::InProgress,
            'progress_percent' => $completed ? 100 : max($progress->progress_percent ?? 0, $evaluation->score),
            'attempts_count' => ($progress->attempts_count ?? 0) + 1,
            'best_score' => max($progress->best_score ?? 0, $evaluation->score),
            'started_at' => $progress->started_at ?? now(),
            'completed_at' => $progress->completed_at ?? ($evaluation->isCorrect() ? now() : null),
            'last_activity_at' => now(),
        ])->save();
    }

    private function timeLimitExceeded(Exercise $exercise, ?Carbon $startedAt): bool
    {
        // 5 s de tolérance pour la latence réseau.
        return $exercise->time_limit_seconds !== null
            && $startedAt !== null
            && $startedAt->copy()->addSeconds($exercise->time_limit_seconds + 5)->isPast();
    }
}
