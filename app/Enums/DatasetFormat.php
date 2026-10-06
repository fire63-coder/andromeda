<?php

namespace App\Enums;

/**
 * Formats d'import des jeux de données.
 */
enum DatasetFormat: string
{
    case SqlDump = 'sql_dump';
    case Csv = 'csv';
    case Json = 'json';
    case Builder = 'builder';

    public function label(): string
    {
        return match ($this) {
            self::SqlDump => 'Dump SQL',
            self::Csv => 'CSV',
            self::Json => 'JSON',
            self::Builder => 'Créé dans l\'application',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $case) => [$case->value => $case->label()])->all();
    }
}
