<?php

namespace App\Services\Datasets;

final class Column
{
    public function __construct(
        public string $name,
        public ColumnType $type = ColumnType::Text,
        public bool $nullable = true,
        public bool $primary = false,
        /** "table.colonne" référencée (clé étrangère) */
        public ?string $references = null,
        /** Longueur maximale (texte) ou [chiffres entiers, décimales] (décimal) observées */
        public int $maxLength = 0,
        public int $scale = 0,
        public int $integerDigits = 1,
    ) {}
}
