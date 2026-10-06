<?php

namespace App\Services\Authoring;

use App\Enums\ExerciseType;
use App\Enums\SubmissionStatus;
use App\Models\Exercise;
use App\Services\Evaluation\SubmissionEvaluator;

/**
 * Vérifie un exercice avant publication en le faisant passer par le vrai moteur d'évaluation :
 * - la solution de référence doit être jugée correcte sur chaque moteur disponible
 *   (jeu visible + jeux de test cachés) ;
 * - pour une correction de bug, le code de départ ne doit PAS l'être ;
 * - pour un QCM, au moins une bonne réponse doit exister.
 */
class SolutionTester
{
    public function __construct(private readonly SubmissionEvaluator $evaluator) {}

    /**
     * @return array{passed: bool, checks: list<array{label: string, ok: bool, message: string}>}
     */
    public function test(Exercise $exercise): array
    {
        $exercise->load(['datasets', 'choices']);
        $checks = [];

        if ($exercise->type === ExerciseType::MultipleChoice) {
            $correct = $exercise->choices->where('is_correct', true);
            $checks[] = [
                'label' => 'QCM',
                'ok' => $exercise->choices->count() >= 2 && $correct->isNotEmpty(),
                'message' => $exercise->choices->count().' réponse(s) dont '.$correct->count().' correcte(s).',
            ];

            return ['passed' => $checks[0]['ok'], 'checks' => $checks];
        }

        $dialects = $this->evaluator->availableDialects($exercise);

        if ($dialects->isEmpty()) {
            return ['passed' => false, 'checks' => [[
                'label' => 'Moteurs',
                'ok' => false,
                'message' => 'Aucun moteur exécutable : liez un jeu de données visible prêt sur au moins un moteur.',
            ]]];
        }

        foreach ($dialects as $dialect) {
            $solution = $this->evaluator->evaluate($exercise, $dialect, $exercise->solution_sql);
            $checks[] = [
                'label' => "Solution · {$dialect->name}",
                'ok' => $solution->status === SubmissionStatus::Correct,
                'message' => $solution->status === SubmissionStatus::Correct
                    ? 'Correcte sur '.$exercise->datasets->count().' jeu(x) de données.'
                    : $solution->message,
            ];

            if ($exercise->type === ExerciseType::BugFix && filled($exercise->starter_sql)) {
                $starter = $this->evaluator->evaluate($exercise, $dialect, $exercise->starter_sql);
                $checks[] = [
                    'label' => "Code bogué · {$dialect->name}",
                    'ok' => $starter->status !== SubmissionStatus::Correct,
                    'message' => $starter->status !== SubmissionStatus::Correct
                        ? 'Échoue bien : « '.$starter->message.' »'
                        : 'Le code de départ est déjà correct : il n\'y a pas de bug à corriger.',
                ];
            }
        }

        return ['passed' => collect($checks)->every('ok'), 'checks' => $checks];
    }
}
