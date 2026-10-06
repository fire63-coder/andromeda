<?php

namespace App\Services\Datasets;

final readonly class AssembledDataset
{
    /**
     * @param  list<Table>  $tables  ordonnées : tables référencées avant celles qui les référencent
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $tables,
        public array $warnings,
    ) {}

    public function totalRows(): int
    {
        return array_sum(array_map(fn (Table $table) => count($table->rows), $this->tables));
    }

    /**
     * Description stockée dans datasets.tables_meta (même format que SchemaIntrospector).
     *
     * @return list<array<string, mixed>>
     */
    public function tablesMeta(): array
    {
        return array_map(fn (Table $table) => [
            'name' => $table->name,
            'rows' => count($table->rows),
            'columns' => array_map(fn (Column $column) => [
                'name' => $column->name,
                'type' => DatasetAssembler::displayType($column),
                'primary' => $column->primary,
                'nullable' => $column->nullable,
                'references' => $column->references,
            ], $table->columns),
        ], $this->tables);
    }
}
