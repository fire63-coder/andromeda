<?php

namespace App\Actions\Challenges;

use App\Exceptions\ContextClosed;
use App\Models\ChallengeParticipation;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\UserSubmission;
use App\Services\Evaluation\SubmissionEvaluator;
use App\Services\Gamification\BadgeEvaluator;
use App\Services\Gamification\StreakService;
use App\Services\Gamification\XpService;
use Illuminate\Support\Facades\DB;

/**
 * Réponse pendant un défi : verdict immédiat, points à la première réussite de chaque
 * exercice, XP = points / 10 × multiplicateur du défi. Le temps de la dernière réussite
 * départage les ex aequo.
 */
class SubmitChallengeAnswer
{
    public function __construct(
        private readonly SubmissionEvaluator $evaluator,
        private readonly XpService $xp,
        private readonly StreakService $streaks,
        private readonly BadgeEvaluator $badges,
    ) {}

    /**
     * @param  list<int>  $choiceIds
     */
    public function handle(ChallengeParticipation $participation, Exercise $exercise, SqlDialect $dialect, ?string $sql, array $choiceIds = []): UserSubmission
    {
        if (! $participation->isOpen()) {
            throw new ContextClosed('Le défi est terminé pour vous : la réponse n\'a pas été prise en compte.');
        }

        $points = $participation->challenge->exercises()->whereKey($exercise->id)->value('challenge_exercise.points');

        if ($points === null) {
            throw new ContextClosed('Cet exercice ne fait pas partie du défi.');
        }

        $evaluation = $this->evaluator->evaluate($exercise, $dialect, $sql, $choiceIds);

        $submission = DB::transaction(function () use ($participation, $exercise, $dialect, $sql, $choiceIds, $evaluation, $points) {
            $participation = $participation->newQuery()->lockForUpdate()->findOrFail($participation->id);

            $alreadySolved = $participation->submissions()
                ->where('exercise_id', $exercise->id)
                ->where('is_correct', true)
                ->exists();

            $earned = $evaluation->isCorrect() && ! $alreadySolved ? (int) $points : 0;
            $xp = (int) round($earned / 10 * (float) $participation->challenge->xp_multiplier);

            $submission = $participation->user->submissions()->create([
                'exercise_id' => $exercise->id,
                'sql_dialect_id' => $dialect->id,
                'context_type' => $participation->getMorphClass(),
                'context_id' => $participation->id,
                'query_sql' => $sql,
                'selected_choice_ids' => $choiceIds ?: null,
                'status' => $evaluation->status,
                'is_correct' => $evaluation->isCorrect(),
                'score' => $evaluation->score,
                'execution_ms' => $evaluation->result?->durationMs,
                'result_preview' => $evaluation->result?->toPreview(20),
                'feedback' => ['message' => $evaluation->message, ...$evaluation->feedback, 'points' => $earned],
                'xp_awarded' => $xp,
            ]);

            if ($earned > 0) {
                $solved = $participation->solved_count + 1;

                $participation->update([
                    'score' => $participation->score + $earned,
                    'solved_count' => $solved,
                    'total_time_ms' => (int) $participation->started_at->diffInMilliseconds(now(), true),
                    'finished_at' => $solved >= $participation->challenge->exercises()->count() ? now() : null,
                ]);

                $this->xp->award($participation->user, $xp, 'challenge_points', $submission, ['challenge' => $participation->challenge->slug]);
            }

            $this->streaks->touch($participation->user);

            return $submission;
        });

        $this->badges->evaluate($participation->user);

        return $submission;
    }
}
