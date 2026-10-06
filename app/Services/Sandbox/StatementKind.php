<?php

namespace App\Services\Sandbox;

/**
 * Familles d'instructions, utilisées dans exercises.validation_options.allowed_statements.
 */
enum StatementKind: string
{
    case Select = 'select';
    case Dml = 'dml';
    case Ddl = 'ddl';

    public function label(): string
    {
        return match ($this) {
            self::Select => 'requêtes de lecture (SELECT)',
            self::Dml => 'modifications de données (INSERT, UPDATE, DELETE)',
            self::Ddl => 'modifications de structure (CREATE, ALTER, DROP)',
        };
    }
}
