<?php

namespace App\Services\Sandbox;

/**
 * Requête validée par le QueryGuard, prête à être exécutée.
 */
final readonly class GuardedQuery
{
    /**
     * @param  list<string>  $statements
     * @param  list<StatementKind>  $kinds
     */
    public function __construct(
        public array $statements,
        public array $kinds,
    ) {}

    public function isReadOnly(): bool
    {
        foreach ($this->kinds as $kind) {
            if ($kind !== StatementKind::Select) {
                return false;
            }
        }

        return true;
    }
}
