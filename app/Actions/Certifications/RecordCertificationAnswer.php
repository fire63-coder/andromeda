<?php

namespace App\Actions\Certifications;

use App\Enums\AttemptStatus;
use App\Models\CertificationAttempt;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\UserSubmission;
use App\Services\Evaluation\SubmissionEvaluator;

/**
 * Enregistre une réponse pendant l'épreuve. Elle est évaluée tout de suite, mais le verdict
 * n'est révélé qu'à la fin ; la dernière réponse à chaque question compte.
 */
class RecordCertificationAnswer
{
    public function __construct(private readonly SubmissionEvaluator $evaluator) {}

    /**
     * @param  list<int>  $choiceIds
     */
    public function handle(CertificationAttempt $attempt, Exercise $exercise, SqlDialect $dialect, ?string $sql, array $choiceIds = []): UserSubmission
    {
        $attempt->refresh();

        if ($attempt->status !== AttemptStatus::InProgress || $attempt->expires_at->isPast()) {
            throw new CertificationException('L\'épreuve est terminée : la réponse n\'a pas été enregistrée.');
        }

        if (! in_array($exercise->id, $attempt->exercise_ids, true)) {
            throw new CertificationException('Cette question ne fait pas partie de votre sujet.');
        }

        $evaluation = $this->evaluator->evaluate($exercise, $dialect, $sql, $choiceIds);

        return $attempt->user->submissions()->create([
            'exercise_id' => $exercise->id,
            'sql_dialect_id' => $dialect->id,
            'context_type' => $attempt->getMorphClass(),
            'context_id' => $attempt->id,
            'query_sql' => $sql,
            'selected_choice_ids' => $choiceIds ?: null,
            'status' => $evaluation->status,
            'is_correct' => $evaluation->isCorrect(),
            'score' => $evaluation->score,
            'execution_ms' => $evaluation->result?->durationMs,
            'result_preview' => $evaluation->result?->toPreview(20),
            // Retour pédagogique conservé pour la correction, montré seulement à la fin.
            'feedback' => ['message' => $evaluation->message, ...$evaluation->feedback],
            'xp_awarded' => 0,
        ]);
    }
}
