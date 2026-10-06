<?php

namespace App\Services\Sandbox;

/**
 * Résultat brut d'une exécution dans une sandbox.
 */
final readonly class QueryResult
{
    public const ERROR_SQL = 'sql';

    public const ERROR_TIMEOUT = 'timeout';

    public const ERROR_REJECTED = 'rejected';

    public const ERROR_INTERNAL = 'internal';

    /**
     * @param  list<string>  $columns  colonnes du dernier jeu de résultats
     * @param  list<list<mixed>>  $rows  au plus max_rows lignes
     * @param  array<string, array{columns: list<string>, rows: list<list<mixed>>}>  $checks  résultats des requêtes de contrôle (state_check)
     */
    public function __construct(
        public bool $success,
        public array $columns = [],
        public array $rows = [],
        public bool $truncated = false,
        public ?int $affectedRows = null,
        public int $durationMs = 0,
        public ?string $error = null,
        public ?string $errorType = null,
        public array $checks = [],
    ) {}

    public static function failure(string $error, string $type = self::ERROR_SQL, int $durationMs = 0): self
    {
        return new self(success: false, durationMs: $durationMs, error: $error, errorType: $type);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            success: (bool) ($payload['success'] ?? false),
            columns: $payload['columns'] ?? [],
            rows: $payload['rows'] ?? [],
            truncated: (bool) ($payload['truncated'] ?? false),
            affectedRows: $payload['affected_rows'] ?? null,
            durationMs: (int) ($payload['duration_ms'] ?? 0),
            error: $payload['error'] ?? null,
            errorType: $payload['error_type'] ?? null,
            checks: $payload['checks'] ?? [],
        );
    }

    public function hasResultSet(): bool
    {
        return $this->columns !== [];
    }

    /**
     * Forme sérialisable envoyée à la vue (aperçu limité).
     *
     * @return array<string, mixed>
     */
    public function toPreview(int $maxRows = 100): array
    {
        return [
            'success' => $this->success,
            'columns' => $this->columns,
            'rows' => array_slice($this->rows, 0, $maxRows),
            'row_count' => count($this->rows),
            'truncated' => $this->truncated || count($this->rows) > $maxRows,
            'affected_rows' => $this->affectedRows,
            'duration_ms' => $this->durationMs,
            'error' => $this->error,
            'error_type' => $this->errorType,
        ];
    }
}
