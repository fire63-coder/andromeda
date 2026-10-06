<?php

namespace App\Services\Datasets;

/**
 * Types canoniques d'une colonne importée, traduits ensuite pour chaque dialecte.
 */
enum ColumnType: string
{
    case Integer = 'integer';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case DateTime = 'datetime';
    case Text = 'text';

    /**
     * Type canonique déduit d'un type déclaré SQL (dumps).
     */
    public static function fromDeclared(string $declared): ?self
    {
        $declared = strtoupper($declared);

        return match (true) {
            $declared === '' => null,
            str_contains($declared, 'BOOL') => self::Boolean,
            str_contains($declared, 'INT') => self::Integer,
            (bool) preg_match('/DEC|NUM|REAL|FLOA|DOUB|MONEY/', $declared) => self::Decimal,
            str_contains($declared, 'TIMESTAMP') || str_contains($declared, 'DATETIME') => self::DateTime,
            str_contains($declared, 'DATE') => self::Date,
            default => self::Text,
        };
    }
}
