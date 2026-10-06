<?php

namespace App\Services\Datasets;

/**
 * Table canonique : colonnes typées et lignes de valeurs PHP (null, int, string...).
 */
final class Table
{
    /**
     * @param  list<Column>  $columns
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(
        public string $name,
        public array $columns = [],
        public array $rows = [],
    ) {}

    public function column(string $name): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }

    public function primaryKey(): ?Column
    {
        foreach ($this->columns as $column) {
            if ($column->primary) {
                return $column;
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    public function values(string $column): array
    {
        $index = array_search($column, array_map(fn (Column $c) => $c->name, $this->columns), true);

        return $index === false ? [] : array_column($this->rows, $index);
    }
}
