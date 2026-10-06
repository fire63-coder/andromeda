<?php

namespace App\Services\Sandbox;

use App\Enums\DatasetStatus;
use App\Enums\SandboxStatus;
use App\Models\Dataset;
use App\Models\DatasetBuild;
use App\Models\SandboxSession;
use App\Models\SqlDialect;
use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\Exceptions\QueryRejected;
use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use Illuminate\Support\Str;

/**
 * Point d'entrée unique pour exécuter du SQL dans une sandbox :
 * garde-fous → résolution du build (jeu de données × dialecte) → moteur.
 */
class SandboxManager
{
    /** @var array<string, SandboxDriver> */
    private array $drivers = [];

    public function __construct(private readonly QueryGuard $guard) {}

    /**
     * Exécute une requête d'élève.
     *
     * @param  array<string, mixed>  $guardOptions  exercises.validation_options (allowed_statements, required_keywords...)
     * @param  array<string, string>  $checkQueries
     */
    public function run(
        Dataset $dataset,
        SqlDialect $dialect,
        string $sql,
        array $guardOptions = [],
        ?int $timeoutMs = null,
        array $checkQueries = [],
    ): QueryResult {
        try {
            $query = $this->guard->inspect($sql, $guardOptions);
        } catch (QueryRejected $e) {
            return QueryResult::failure($e->getMessage(), QueryResult::ERROR_REJECTED);
        }

        return $this->execute($dataset, $dialect, $query, $timeoutMs, $checkQueries);
    }

    /**
     * Exécute une requête de référence (solution d'un exercice, écrite par un formateur) :
     * toutes les familles d'instructions sont permises, les interdits de sécurité restent actifs.
     *
     * @param  array<string, string>  $checkQueries
     */
    public function runReference(
        Dataset $dataset,
        SqlDialect $dialect,
        string $sql,
        ?int $timeoutMs = null,
        array $checkQueries = [],
    ): QueryResult {
        try {
            $query = $this->guard->inspect($sql, [
                'allowed_statements' => array_map(fn (StatementKind $kind) => $kind->value, StatementKind::cases()),
                'max_statements' => 50,
            ]);
        } catch (QueryRejected $e) {
            return QueryResult::failure('Solution de référence invalide : '.$e->getMessage(), QueryResult::ERROR_INTERNAL);
        }

        return $this->execute($dataset, $dialect, $query, $timeoutMs, $checkQueries);
    }

    public function isExecutable(Dataset $dataset, SqlDialect $dialect): bool
    {
        try {
            $this->driver($dialect);
            $this->build($dataset, $dialect);

            return true;
        } catch (SandboxUnavailable) {
            return false;
        }
    }

    public function driver(SqlDialect $dialect): SandboxDriver
    {
        $config = config("sandbox.drivers.{$dialect->slug}");

        if (! $dialect->is_sandbox_enabled || ! is_array($config)) {
            throw new SandboxUnavailable("L'exécution {$dialect->name} n'est pas encore disponible.");
        }

        return $this->drivers[$dialect->slug] ??= new $config['driver']($config);
    }

    public function build(Dataset $dataset, SqlDialect $dialect): DatasetBuild
    {
        $build = $dataset->builds()
            ->where('sql_dialect_id', $dialect->id)
            ->where('status', DatasetStatus::Ready)
            ->first();

        if (! $build) {
            throw new SandboxUnavailable("Le jeu de données « {$dataset->name} » n'est pas disponible en {$dialect->name}.");
        }

        return $build;
    }

    /**
     * @param  array<string, string>  $checkQueries
     */
    private function execute(
        Dataset $dataset,
        SqlDialect $dialect,
        GuardedQuery $query,
        ?int $timeoutMs,
        array $checkQueries,
    ): QueryResult {
        try {
            $driver = $this->driver($dialect);
            $build = $this->build($dataset, $dialect);
            $resource = $driver->prepare($build);
        } catch (SandboxUnavailable $e) {
            return QueryResult::failure($e->getMessage(), QueryResult::ERROR_INTERNAL);
        }

        $this->track($build, $resource);

        $timeoutMs = min($timeoutMs ?? PHP_INT_MAX, (int) config('sandbox.max_execution_ms'));

        return $driver->execute($build, $query, $timeoutMs, (int) config('sandbox.max_rows'), $checkQueries);
    }

    /**
     * Inventaire des ressources matérialisées (sandbox_sessions), utilisé pour le nettoyage.
     */
    private function track(DatasetBuild $build, string $resource): void
    {
        $session = SandboxSession::firstOrCreate(
            ['sql_dialect_id' => $build->sql_dialect_id, 'resource_name' => $resource],
            [
                'uuid' => (string) Str::uuid(),
                'dataset_id' => $build->dataset_id,
                'status' => SandboxStatus::Ready,
                'expires_at' => now()->addDays(30),
            ],
        );

        $session->increment('queries_count', 1, [
            'last_used_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
