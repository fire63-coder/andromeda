<?php

namespace App\Services\Evaluation;

use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Support\Facades\Cache;

/**
 * Résultat attendu d'un exercice sur un jeu de données donné : la solution de
 * référence est exécutée une fois puis mise en cache. La clé dépend de la
 * solution, des requêtes de contrôle et de la version du build, donc toute
 * modification invalide le cache.
 */
class ExpectedResultResolver
{
    public function __construct(private readonly SandboxManager $sandbox) {}

    public function resolve(Exercise $exercise, Dataset $dataset, SqlDialect $dialect): QueryResult
    {
        if (blank($exercise->solution_sql)) {
            // Exercice sans solution exécutable : snapshot saisi à la main (jeu visible uniquement).
            return is_array($exercise->expected_result)
                ? QueryResult::fromArray(['success' => true, ...$exercise->expected_result])
                : QueryResult::failure('Aucune solution de référence.', QueryResult::ERROR_INTERNAL);
        }

        $checks = $exercise->validation_options['check_queries'] ?? [];
        $build = $this->sandbox->build($dataset, $dialect);

        $key = 'exercise-expected:'.md5(implode('|', [
            $exercise->id,
            $exercise->solution_sql,
            json_encode($checks),
            $build->id,
            $build->updated_at?->toIso8601String(),
        ]));

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return QueryResult::fromArray($cached);
        }

        $result = $this->sandbox->runReference($dataset, $dialect, $exercise->solution_sql, checkQueries: $checks);

        // On ne met en cache que les succès : une erreur doit pouvoir être corrigée sans purge.
        if ($result->success) {
            Cache::put($key, [
                'success' => true,
                'columns' => $result->columns,
                'rows' => $result->rows,
                'affected_rows' => $result->affectedRows,
                'checks' => $result->checks,
            ], now()->addDay());
        }

        return $result;
    }
}
