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
use App\Services\Sandbox\Exceptions\QueryRejected;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use App\Services\Sandbox\Scenario\ScenarioParser;
use App\Services\Sandbox\SqlLexer;
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
        private readonly PlanInspector $plans,
        private readonly SqlLexer $lexer,
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

        if ($exercise->validation_strategy === ValidationStrategy::Concurrency) {
            return $this->runScenario($exercise, $dataset, $dialect, $sql);
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

        if ($exercise->validation_strategy === ValidationStrategy::Concurrency) {
            return $this->evaluateScenario($exercise, $dialect, $sql, $datasets);
        }

        $planQuery = $exercise->validation_options['plan_query'] ?? null;

        // Index à créer pour une requête donnée : seul le plan compte, sur le jeu visible.
        if ($exercise->validation_strategy === ValidationStrategy::QueryPlan && filled($planQuery)) {
            return $this->checkPlan($exercise, $dialect, $datasets->first(), $sql, $planQuery)
                ?? new EvaluationResult(SubmissionStatus::Correct, 100, 'Bravo ! La requête s\'appuie désormais sur un index.');
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

        // Requête à réécrire : bon résultat partout, puis recherche indexée sur le jeu visible.
        if ($exercise->validation_strategy === ValidationStrategy::QueryPlan) {
            $ownQuery = $this->lexer->statements($sql)[0] ?? $sql;

            if ($verdict = $this->checkPlan($exercise, $dialect, $datasets->first(), $sql, $ownQuery)) {
                return $verdict;
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
        $needsPlans = $exercise->validation_strategy === ValidationStrategy::QueryPlan;
        $needsScenarios = $exercise->validation_strategy === ValidationStrategy::Concurrency;

        return SqlDialect::query()
            ->executable()
            ->when($exercise->sql_dialect_id, fn ($query) => $query->whereKey($exercise->sql_dialect_id))
            ->orderBy('position')
            ->get()
            ->filter(fn (SqlDialect $dialect) => $datasets->every(
                fn (Dataset $dataset) => $this->sandbox->isExecutable($dataset, $dialect),
            ) && (! $needsDdl || $this->sandbox->driver($dialect)->supportsDdl())
                && (! $needsRoutines || $this->sandbox->driver($dialect)->supportsRoutines())
                && (! $needsPlans || $this->plans->supports($dialect))
                && (! $needsScenarios || $this->sandbox->supportsScenarios($dialect)))
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
            $this->guardOptions($exercise, $dataset),
            $exercise->max_execution_ms,
            $exercise->validation_strategy === ValidationStrategy::StateCheck ? ($options['check_queries'] ?? []) : [],
        );
    }

    /**
     * Garde-fous de l'exercice, plus les tables du jeu de données à protéger : un élève ne doit pas
     * pouvoir les supprimer, les renommer ou les masquer par une table temporaire fabriquée, que les
     * requêtes de contrôle liraient à la place.
     *
     * @return array<string, mixed>
     */
    private function guardOptions(Exercise $exercise, Dataset $dataset): array
    {
        $options = $exercise->validation_options ?? [];

        return [
            ...array_intersect_key($options, array_flip(self::GUARD_OPTIONS)),
            'protected_tables' => ($options['allow_table_replacement'] ?? false) ? [] : array_column($dataset->tables_meta ?? [], 'name'),
        ];
    }

    /**
     * Scénario de concurrence : même entrelacement des sessions que la solution, puis, sur chaque
     * jeu de données, mêmes résultats aux étapes comparées et même état final que la solution.
     *
     * @param  Collection<int, Dataset>  $datasets
     */
    private function evaluateScenario(Exercise $exercise, SqlDialect $dialect, string $sql, Collection $datasets): EvaluationResult
    {
        $options = $exercise->validation_options ?? [];
        $parser = app(ScenarioParser::class);

        try {
            $expectedOrder = ScenarioParser::interleaving($parser->parse((string) $exercise->solution_sql));
            $actualOrder = ScenarioParser::interleaving($parser->parse($sql, $options));
        } catch (QueryRejected $e) {
            return new EvaluationResult(SubmissionStatus::Rejected, 0, $e->getMessage());
        }

        if ($actualOrder !== $expectedOrder) {
            return new EvaluationResult(SubmissionStatus::Rejected, 0,
                "L'enchaînement des sessions doit rester celui de l'énoncé (".implode(' → ', str_split($expectedOrder)).'). '
                .'Modifiez le contenu des étapes, pas leur ordre : c\'est l\'entrelacement qui crée le problème à résoudre.');
        }

        $primaryResult = null;

        foreach ($datasets as $index => $dataset) {
            $actual = $this->runScenario($exercise, $dataset, $dialect, $sql);
            $primaryResult ??= $actual;

            if (! $actual->success) {
                return new EvaluationResult($this->statusForError($actual), 0, $actual->error, result: $primaryResult);
            }

            $expected = $this->runScenario($exercise, $dataset, $dialect, (string) $exercise->solution_sql);

            if (! $expected->success) {
                return $this->misconfigured('le scénario de référence ne s\'exécute pas', $primaryResult);
            }

            if ($options['no_errors'] ?? false) {
                foreach ($actual->timeline as $step) {
                    if ($step['error'] !== null) {
                        return new EvaluationResult(SubmissionStatus::Wrong, 0,
                            "L'étape {$step['step']} (session {$step['session']}) échoue : ".strtok($step['error'], "\n")
                            .'. Toutes les transactions doivent aboutir.', ['step' => $step['step']], $primaryResult);
                    }
                }
            }

            foreach ($options['compare_steps'] ?? [] as $number) {
                $mine = $actual->timeline[$number - 1] ?? null;
                $reference = $expected->timeline[$number - 1] ?? null;
                $comparison = $this->comparator->compare(
                    ['columns' => $mine['columns'] ?? [], 'rows' => $mine['rows'] ?? []],
                    ['columns' => $reference['columns'] ?? [], 'rows' => $reference['rows'] ?? []],
                    true,
                    $options,
                );

                if (! $comparison->matches || ($mine['error'] ?? null) !== null) {
                    return new EvaluationResult(SubmissionStatus::Wrong, 0,
                        "À l'étape {$number} (session {$reference['session']}), le résultat n'est pas celui attendu. "
                        .(($mine['error'] ?? null) ? 'Elle échoue : '.strtok($mine['error'], "\n") : $comparison->message),
                        ['step' => $number], $primaryResult);
                }
            }

            $comparison = $this->compareChecks($actual, $expected, $options);

            if (! $comparison->matches) {
                return new EvaluationResult(SubmissionStatus::Wrong, $index === 0 ? 0 : 50, $comparison->message, $comparison->details, $primaryResult);
            }
        }

        return new EvaluationResult(SubmissionStatus::Correct, 100, 'Bravo ! Les transactions se déroulent correctement, même entrelacées.', result: $primaryResult);
    }

    private function runScenario(Exercise $exercise, Dataset $dataset, SqlDialect $dialect, string $sql): QueryResult
    {
        $options = $exercise->validation_options ?? [];

        return $this->sandbox->runScenario(
            $dataset,
            $dialect,
            $sql,
            array_intersect_key($options, array_flip(['required_keywords', 'forbidden_keywords'])),
            $exercise->max_execution_ms,
            $options['check_queries'] ?? [],
        );
    }

    /**
     * Exécute le code de l'élève puis le plan de $planQuery : chaque table de
     * validation_options.index_tables doit être atteinte par une recherche indexée.
     */
    private function checkPlan(Exercise $exercise, SqlDialect $dialect, Dataset $dataset, string $sql, string $planQuery): ?EvaluationResult
    {
        $options = $exercise->validation_options ?? [];

        $result = $this->sandbox->run(
            $dataset,
            $dialect,
            $sql,
            $this->guardOptions($exercise, $dataset),
            $exercise->max_execution_ms,
            $this->plans->checkQueries($dialect, $planQuery),
        );

        if (! $result->success) {
            return new EvaluationResult($this->statusForError($result), 0, $result->error, result: $result);
        }

        $accesses = $this->plans->accesses($dialect, $result->checks, $planQuery);
        $plan = ['plan' => array_column($accesses, 'detail')];

        foreach ($options['index_tables'] ?? [] as $table) {
            $hits = array_filter($accesses, fn (array $access) => $access['table'] === strtolower($table));

            if ($hits === []) {
                return $this->misconfigured("la table {$table} n'apparaît pas dans le plan d'exécution", $result);
            }

            foreach ($hits as $access) {
                if (! $access['indexed']) {
                    return new EvaluationResult(
                        SubmissionStatus::Wrong,
                        0,
                        "La table « {$table} » est encore parcourue en entier ({$access['detail']}). "
                            .'Le moteur ne trouve pas d\'index utilisable pour la condition : vérifiez la colonne indexée, '
                            .'et qu\'aucune fonction ni conversion n\'est appliquée à la colonne filtrée.',
                        $plan,
                        $result,
                    );
                }
            }
        }

        return null;
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

        return $this->compareChecks($actual, $expected, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function compareChecks(QueryResult $actual, QueryResult $expected, array $options): Comparison
    {
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
