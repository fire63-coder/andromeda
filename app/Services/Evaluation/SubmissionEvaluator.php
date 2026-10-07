<?php

namespace App\Services\Evaluation;

use App\Enums\DatasetRole;
use App\Enums\ExerciseType;
use App\Enums\SubmissionStatus;
use App\Enums\ValidationStrategy;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Models\User;
use App\Services\Evaluation\Comparators\ResultSetComparator;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Support\Collection;

/**
 * Cœur pédagogique : exécute la requête de l'élève sur chaque jeu de données
 * de l'exercice (visible puis tests cachés) et la confronte au résultat attendu.
 */
class SubmissionEvaluator
{
    /** Options transmises au QueryGuard. */
    private const GUARD_OPTIONS = ['allowed_statements', 'max_statements', 'required_keywords', 'forbidden_keywords'];

    public function __construct(
        private readonly SandboxManager $sandbox,
        private readonly ExpectedResultResolver $expected,
        private readonly ResultSetComparator $comparator,
    ) {}

    /**
     * Bouton « Exécuter » : la requête tourne sur le jeu visible, sans verdict.
     */
    public function preview(Exercise $exercise, SqlDialect $dialect, string $sql): QueryResult
    {
        $dataset = $this->datasets($exercise)->first();

        if (! $dataset) {
            return QueryResult::failure('Cet exercice n\'a pas de jeu de données.', QueryResult::ERROR_INTERNAL);
        }

        return $this->runStudent($exercise, $dataset, $dialect, $sql);
    }

    /**
     * Bouton « Valider ».
     *
     * @param  list<int>  $choiceIds  réponses cochées (QCM)
     */
    public function evaluate(Exercise $exercise, SqlDialect $dialect, ?string $sql, array $choiceIds = []): EvaluationResult
    {
        if ($exercise->type === ExerciseType::MultipleChoice) {
            return $this->evaluateChoices($exercise, $choiceIds);
        }

        if (blank($sql)) {
            return new EvaluationResult(SubmissionStatus::Rejected, 0, 'Écrivez une requête avant de valider.');
        }

        $datasets = $this->datasets($exercise);

        if ($datasets->isEmpty()) {
            return $this->misconfigured('aucun jeu de données lié');
        }

        $primaryResult = null;

        foreach ($datasets as $index => $dataset) {
            $isPrimary = $index === 0;
            $actual = $this->runStudent($exercise, $dataset, $dialect, $sql);
            $primaryResult ??= $actual;

            if (! $actual->success) {
                // Une erreur sur un jeu caché (ex. division par zéro) est signalée sans dévoiler ses données.
                return new EvaluationResult(
                    $this->statusForError($actual),
                    0,
                    $isPrimary ? $actual->error : 'Votre requête échoue sur un jeu de test caché : '.$actual->error,
                    result: $primaryResult,
                );
            }

            $expected = $this->expected->resolve($exercise, $dataset, $dialect);

            if (! $expected->success) {
                report(new \RuntimeException("Exercice {$exercise->id} : solution de référence en échec ({$expected->error})."));

                return $this->misconfigured('la solution de référence ne s\'exécute pas', $primaryResult);
            }

            $comparison = $this->compare($exercise, $actual, $expected);

            if (! $comparison->matches) {
                return $isPrimary
                    ? new EvaluationResult(SubmissionStatus::Wrong, 0, $comparison->message, $comparison->details, $primaryResult)
                    : new EvaluationResult(
                        SubmissionStatus::Wrong,
                        50,
                        isset($comparison->details['first_difference'])
                            ? 'Sur un jeu de test caché, les lignes ne sortent pas dans le bon ordre. '
                                .'Sans ORDER BY, l\'ordre des lignes n\'est jamais garanti : triez explicitement.'
                            : 'Votre requête donne le bon résultat sur le jeu visible, mais pas sur les jeux de test cachés. '
                                .'Évitez de coder en dur des identifiants ou des valeurs : votre requête doit fonctionner quelles que soient les données.',
                        ['hidden_dataset_failed' => true],
                        $primaryResult,
                    );
            }
        }

        return new EvaluationResult(
            SubmissionStatus::Correct,
            100,
            $datasets->count() > 1
                ? 'Bravo ! Votre requête est correcte, y compris sur les jeux de test cachés.'
                : 'Bravo ! Votre requête est correcte.',
            result: $primaryResult,
        );
    }

    /**
     * Dialectes dans lesquels l'élève peut résoudre l'exercice.
     *
     * @return Collection<int, SqlDialect>
     */
    public function availableDialects(Exercise $exercise): Collection
    {
        $datasets = $this->datasets($exercise);
        $needsDdl = in_array('ddl', $exercise->validation_options['allowed_statements'] ?? [], true);
        $needsRoutines = in_array('routine', $exercise->validation_options['allowed_statements'] ?? [], true);

        return SqlDialect::query()
            ->executable()
            ->when($exercise->sql_dialect_id, fn ($query) => $query->whereKey($exercise->sql_dialect_id))
            ->orderBy('position')
            ->get()
            ->filter(fn (SqlDialect $dialect) => $datasets->every(
                fn (Dataset $dataset) => $this->sandbox->isExecutable($dataset, $dialect),
            ) && (! $needsDdl || $this->sandbox->driver($dialect)->supportsDdl())
                && (! $needsRoutines || $this->sandbox->driver($dialect)->supportsRoutines()))
            ->values();
    }

    public function defaultDialect(Exercise $exercise, ?User $user = null): ?SqlDialect
    {
        $available = $this->availableDialects($exercise);

        return $available->firstWhere('id', $user?->preferred_dialect_id)
            ?? $available->firstWhere('is_default', true)
            ?? $available->first();
    }

    /**
     * Jeu visible en premier, puis jeux de test cachés.
     *
     * @return Collection<int, Dataset>
     */
    private function datasets(Exercise $exercise): Collection
    {
        return $exercise->datasets
            ->sortBy(fn (Dataset $dataset) => [$dataset->pivot->role === DatasetRole::Primary->value ? 0 : 1, $dataset->pivot->position])
            ->values();
    }

    private function runStudent(Exercise $exercise, Dataset $dataset, SqlDialect $dialect, string $sql): QueryResult
    {
        $options = $exercise->validation_options ?? [];

        return $this->sandbox->run(
            $dataset,
            $dialect,
            $sql,
            array_intersect_key($options, array_flip(self::GUARD_OPTIONS)),
            $exercise->max_execution_ms,
            $exercise->validation_strategy === ValidationStrategy::StateCheck ? ($options['check_queries'] ?? []) : [],
        );
    }

    private function compare(Exercise $exercise, QueryResult $actual, QueryResult $expected): Comparison
    {
        $options = $exercise->validation_options ?? [];

        if ($exercise->validation_strategy !== ValidationStrategy::StateCheck) {
            return $this->comparator->compare(
                ['columns' => $actual->columns, 'rows' => $actual->rows],
                ['columns' => $expected->columns, 'rows' => $expected->rows],
                $exercise->validation_strategy === ValidationStrategy::OrderedResultSet,
                $options,
            );
        }

        foreach ($expected->checks as $name => $expectedCheck) {
            $comparison = $this->comparator->compare($actual->checks[$name] ?? ['columns' => [], 'rows' => []], $expectedCheck, true, $options);

            if (! $comparison->matches) {
                return Comparison::mismatch(
                    "Après exécution, les données de « {$name} » ne sont pas dans l'état attendu. {$comparison->message}",
                    ['check' => $name, ...$comparison->details],
                );
            }
        }

        return Comparison::match();
    }

    /**
     * @param  list<int>  $choiceIds
     */
    private function evaluateChoices(Exercise $exercise, array $choiceIds): EvaluationResult
    {
        if ($choiceIds === []) {
            return new EvaluationResult(SubmissionStatus::Rejected, 0, 'Sélectionnez au moins une réponse.');
        }

        $choices = $exercise->choices;
        $correct = $choices->where('is_correct', true)->pluck('id')->sort()->values()->all();
        $selected = collect($choiceIds)->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        $explanations = $choices
            ->whereIn('id', $selected)
            ->map(fn ($choice) => ['id' => $choice->id, 'body' => $choice->body, 'correct' => $choice->is_correct, 'explanation' => $choice->explanation])
            ->values()
            ->all();

        return $selected === $correct
            ? new EvaluationResult(SubmissionStatus::Correct, 100, 'Bonne réponse !', ['choices' => $explanations])
            : new EvaluationResult(SubmissionStatus::Wrong, 0, 'Ce n\'est pas la bonne réponse.', ['choices' => $explanations]);
    }

    private function statusForError(QueryResult $result): SubmissionStatus
    {
        return match ($result->errorType) {
            QueryResult::ERROR_TIMEOUT => SubmissionStatus::Timeout,
            QueryResult::ERROR_REJECTED => SubmissionStatus::Rejected,
            default => SubmissionStatus::Error,
        };
    }

    private function misconfigured(string $reason, ?QueryResult $result = null): EvaluationResult
    {
        return new EvaluationResult(
            SubmissionStatus::Error,
            0,
            "Cet exercice est mal configuré ({$reason}). L'équipe pédagogique a été prévenue.",
            result: $result,
        );
    }
}
