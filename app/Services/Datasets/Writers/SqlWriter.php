<?php

namespace App\Services\Datasets\Writers;

use App\Services\Datasets\AssembledDataset;
use App\Services\Datasets\Column;
use App\Services\Datasets\ColumnType;
use App\Services\Datasets\DatasetAssembler;
use App\Services\Datasets\Table;
use InvalidArgumentException;

/**
 * Génère le DDL et les INSERT d'un jeu canonique pour un dialecte cible.
 * Identifiants toujours délimités, valeurs littérales échappées selon le moteur.
 */
class SqlWriter
{
    public const DIALECTS = ['sqlite', 'pgsql', 'mysql'];

    private const ROWS_PER_INSERT = 500;

    public function __construct(private readonly string $dialect)
    {
        if (! in_array($dialect, self::DIALECTS, true)) {
            throw new InvalidArgumentException("Dialecte non pris en charge par l'import : {$dialect}");
        }
    }

    /**
     * @return array{schema: string, seed: string}
     */
    public function write(AssembledDataset $dataset): array
    {
        return [
            'schema' => implode("\n\n", array_map($this->createTable(...), $dataset->tables)),
            'seed' => implode("\n\n", array_filter(array_map($this->inserts(...), $dataset->tables))),
        ];
    }

    private function createTable(Table $table): string
    {
        $lines = array_map(function (Column $column) {
            $line = '    '.$this->quote($column->name).' '.$this->type($column);
            $line .= $column->primary ? ' PRIMARY KEY' : ($column->nullable ? '' : ' NOT NULL');

            if ($column->references) {
                [$targetTable, $targetColumn] = explode('.', $column->references);
                $line .= ' REFERENCES '.$this->quote($targetTable).' ('.$this->quote($targetColumn).')';
            }

            return $line;
        }, $table->columns);

        return 'CREATE TABLE '.$this->quote($table->name)." (\n".implode(",\n", $lines)."\n);";
    }

    private function inserts(Table $table): string
    {
        if ($table->rows === []) {
            return '';
        }

        $prefix = 'INSERT INTO '.$this->quote($table->name).' ('
            .implode(', ', array_map(fn (Column $column) => $this->quote($column->name), $table->columns))
            .') VALUES';

        $statements = [];
        foreach (array_chunk($table->rows, self::ROWS_PER_INSERT) as $chunk) {
            $values = array_map(
                fn (array $row) => '    ('.implode(', ', array_map(
                    fn (mixed $value, int $index) => $this->literal($value, $table->columns[$index]->type),
                    $row,
                    array_keys($row),
                )).')',
                $chunk,
            );

            $statements[] = $prefix."\n".implode(",\n", $values).';';
        }

        return implode("\n", $statements);
    }

    private function type(Column $column): string
    {
        $text = DatasetAssembler::isShortText($column) || $column->primary ? 'VARCHAR(255)' : 'TEXT';

        return match ([$this->dialect, $column->type]) {
            ['sqlite', ColumnType::Boolean] => 'INTEGER',
            ['sqlite', ColumnType::DateTime] => 'TEXT',
            ['mysql', ColumnType::Integer] => 'BIGINT',
            ['mysql', ColumnType::DateTime] => 'DATETIME',
            ['pgsql', ColumnType::Integer] => 'BIGINT',
            default => match ($column->type) {
                ColumnType::Integer => 'INTEGER',
                ColumnType::Decimal => 'NUMERIC('.DatasetAssembler::precision($column).', '.$column->scale.')',
                ColumnType::Boolean => 'BOOLEAN',
                ColumnType::Date => 'DATE',
                ColumnType::DateTime => 'TIMESTAMP',
                ColumnType::Text => $text,
            },
        };
    }

    private function literal(mixed $value, ColumnType $type): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return match ($type) {
            ColumnType::Integer => (string) (int) $value,
            ColumnType::Decimal => is_numeric($value) ? (string) $value : 'NULL',
            ColumnType::Boolean => $this->dialect === 'pgsql' ? ($value ? 'TRUE' : 'FALSE') : ($value ? '1' : '0'),
            default => $this->string((string) $value),
        };
    }

    private function string(string $value): string
    {
        $escaped = str_replace("'", "''", $value);

        if ($this->dialect === 'mysql') {
            // MySQL interprète les antislashs dans les chaînes (sauf mode NO_BACKSLASH_ESCAPES).
            $escaped = str_replace('\\', '\\\\', $escaped);
        }

        return "'{$escaped}'";
    }

    private function quote(string $identifier): string
    {
        return $this->dialect === 'mysql'
            ? '`'.str_replace('`', '``', $identifier).'`'
            : '"'.str_replace('"', '""', $identifier).'"';
    }
}
