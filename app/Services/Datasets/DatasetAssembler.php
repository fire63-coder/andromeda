<?php

namespace App\Services\Datasets;

use Illuminate\Support\Str;

/**
 * Transforme des tables brutes (CSV, JSON, dump) en jeu de données canonique :
 * noms nettoyés, types déduits, valeurs converties, clés primaires et étrangères
 * détectées, tables ordonnées selon leurs dépendances, limites vérifiées.
 */
class DatasetAssembler
{
    public const MAX_TABLES = 30;

    public const MAX_COLUMNS = 100;

    public const MAX_ROWS = 200_000;

    public function __construct(private readonly TypeInferrer $types = new TypeInferrer) {}

    /**
     * @param  list<Table>  $tables
     * @param  array<string, string>  $renames  nom de table d'origine => nouveau nom
     * @param  bool  $declaredTypes  types déjà connus (dump SQL) : on ne les redéduit pas
     */
    public function assemble(array $tables, array $renames = [], bool $declaredTypes = false): AssembledDataset
    {
        $this->checkLimits($tables);

        $sanitizer = new IdentifierSanitizer;
        // Les renommages se font sur le nom tel qu'affiché dans l'aperçu, c'est-à-dire déjà nettoyé.
        $tableNames = $sanitizer->sanitizeAll(
            array_map(function (Table $table) use ($renames) {
                $displayed = (new IdentifierSanitizer)->sanitize($table->name, 'table');

                return $renames[$displayed] ?? $renames[$table->name] ?? $table->name;
            }, $tables),
            'table',
        );

        $renamedTables = [];
        foreach ($tables as $index => $table) {
            $renamedTables[$table->name] = $tableNames[$index];
            $table->name = $tableNames[$index];

            $columnNames = $sanitizer->sanitizeAll(array_map(fn (Column $column) => $column->name, $table->columns));
            foreach ($table->columns as $position => $column) {
                $column->name = $columnNames[$position];
            }
        }

        foreach ($tables as $table) {
            $this->type($table, $declaredTypes);
            $this->detectPrimaryKey($table);
        }

        foreach ($tables as $table) {
            foreach ($table->columns as $column) {
                $this->resolveReference($column, $table, $tables, $renamedTables, $sanitizer);
            }
        }

        return new AssembledDataset($this->sortByDependencies($tables, $sanitizer), $sanitizer->warnings);
    }

    public static function displayType(Column $column): string
    {
        return match ($column->type) {
            ColumnType::Integer => 'INTEGER',
            ColumnType::Decimal => 'NUMERIC('.self::precision($column).', '.$column->scale.')',
            ColumnType::Boolean => 'BOOLEAN',
            ColumnType::Date => 'DATE',
            ColumnType::DateTime => 'TIMESTAMP',
            ColumnType::Text => self::isShortText($column) ? 'VARCHAR(255)' : 'TEXT',
        };
    }

    public static function precision(Column $column): int
    {
        return max(10, min(38, $column->integerDigits + $column->scale + 2));
    }

    public static function isShortText(Column $column): bool
    {
        return $column->maxLength <= 255;
    }

    /**
     * @param  list<Table>  $tables
     */
    private function checkLimits(array $tables): void
    {
        if ($tables === []) {
            throw new ImportException('Aucune table à importer.');
        }

        if (count($tables) > self::MAX_TABLES) {
            throw new ImportException('Au plus '.self::MAX_TABLES.' tables par jeu de données.');
        }

        $rows = 0;
        foreach ($tables as $table) {
            if ($table->columns === []) {
                throw new ImportException("La table {$table->name} n'a aucune colonne.");
            }

            if (count($table->columns) > self::MAX_COLUMNS) {
                throw new ImportException("La table {$table->name} dépasse ".self::MAX_COLUMNS.' colonnes.');
            }

            $rows += count($table->rows);
        }

        if ($rows > self::MAX_ROWS) {
            throw new ImportException('Le jeu de données dépasse '.number_format(self::MAX_ROWS, 0, ',', ' ').' lignes au total.');
        }
    }

    private function type(Table $table, bool $declaredTypes): void
    {
        foreach ($table->columns as $index => $column) {
            $values = array_column($table->rows, $index);

            if ($declaredTypes) {
                $this->types->measure($column, $values);
            } else {
                $this->types->infer($column, $values);
            }

            foreach ($table->rows as $rowIndex => $row) {
                $table->rows[$rowIndex][$index] = $this->types->cast($row[$index] ?? null, $column->type);
            }

            if (! $declaredTypes) {
                $column->nullable = in_array(null, array_column($table->rows, $index), true);
            }
        }
    }

    /**
     * Clé déclarée (dump) ou, à défaut, une colonne « id » entière, unique et non nulle.
     */
    private function detectPrimaryKey(Table $table): void
    {
        if ($table->primaryKey()) {
            return;
        }

        $id = $table->column('id');

        if ($id && $id->type === ColumnType::Integer && ! $id->nullable && $this->isUnique($table->values('id'))) {
            $id->primary = true;
        }
    }

    /**
     * Garde une clé étrangère déclarée si elle est cohérente, sinon en déduit une
     * pour les colonnes « xxx_id » quand une table xxx / xxxs a une clé primaire
     * et que toutes les valeurs existent : sinon la contrainte ferait échouer l'import.
     *
     * @param  list<Table>  $tables
     * @param  array<string, string>  $renamedTables
     */
    private function resolveReference(Column $column, Table $table, array $tables, array $renamedTables, IdentifierSanitizer $sanitizer): void
    {
        $byName = collect($tables)->keyBy('name');

        if ($column->references) {
            [$targetTable, $targetColumn] = explode('.', $column->references) + [1 => 'id'];
            $targetTable = $renamedTables[$targetTable] ?? $targetTable;
            $target = $byName->get($targetTable);
            $column->references = $target && $target->column($targetColumn)?->primary ? "{$targetTable}.{$targetColumn}" : null;
        } elseif (! $column->primary && $column->type === ColumnType::Integer && str_ends_with($column->name, '_id')) {
            $stem = substr($column->name, 0, -3);
            $target = $byName->first(fn (Table $candidate) => $candidate !== $table
                && in_array($candidate->name, [$stem, Str::plural($stem), $stem.'s', $stem.'es'], true)
                && $candidate->primaryKey()?->type === ColumnType::Integer);
            $column->references = $target ? "{$target->name}.{$target->primaryKey()->name}" : null;
        }

        if ($column->references === null) {
            return;
        }

        [$targetTable, $targetColumn] = explode('.', $column->references);
        $existing = array_flip(array_map('strval', $byName[$targetTable]->values($targetColumn)));

        foreach ($table->values($column->name) as $value) {
            if ($value !== null && ! isset($existing[(string) $value])) {
                $sanitizer->warnings[] = "{$table->name}.{$column->name} : la valeur {$value} n'existe pas dans {$column->references}, clé étrangère ignorée.";
                $column->references = null;

                return;
            }
        }
    }

    /**
     * @param  list<mixed>  $values
     */
    private function isUnique(array $values): bool
    {
        return count($values) === count(array_unique(array_map('strval', $values)));
    }

    /**
     * Tri topologique : une table référencée est créée avant celles qui la référencent.
     * En cas de cycle, les clés étrangères en cause sont retirées.
     *
     * @param  list<Table>  $tables
     * @return list<Table>
     */
    private function sortByDependencies(array $tables, IdentifierSanitizer $sanitizer): array
    {
        $sorted = [];
        $visiting = [];
        $byName = collect($tables)->keyBy('name');

        $visit = function (Table $table) use (&$visit, &$sorted, &$visiting, $byName, $sanitizer) {
            if (isset($sorted[$table->name])) {
                return;
            }

            $visiting[$table->name] = true;

            foreach ($table->columns as $column) {
                if (! $column->references) {
                    continue;
                }

                $target = explode('.', $column->references)[0];

                if ($target === $table->name) {
                    continue; // auto-référence (hiérarchie) : sans conséquence sur l'ordre
                }

                if (isset($visiting[$target])) {
                    $sanitizer->warnings[] = "Référence circulaire {$table->name}.{$column->name} → {$target} : clé étrangère ignorée.";
                    $column->references = null;

                    continue;
                }

                $visit($byName[$target]);
            }

            unset($visiting[$table->name]);
            $sorted[$table->name] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return array_values($sorted);
    }
}
