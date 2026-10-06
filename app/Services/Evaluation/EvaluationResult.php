<?php

namespace App\Services\Evaluation;

use App\Enums\SubmissionStatus;
use App\Services\Sandbox\QueryResult;

/**
 * Verdict complet d'une soumission, avant enregistrement.
 */
final readonly class EvaluationResult
{
    /**
     * @param  array<string, mixed>  $feedback  détail affichable (diff, explications de QCM...)
     * @param  ?QueryResult  $result  exécution sur le jeu visible (aperçu montré à l'élève)
     */
    public function __construct(
        public SubmissionStatus $status,
        public int $score,
        public string $message,
        public array $feedback = [],
        public ?QueryResult $result = null,
    ) {}

    public function isCorrect(): bool
    {
        return $this->status === SubmissionStatus::Correct;
    }
}
