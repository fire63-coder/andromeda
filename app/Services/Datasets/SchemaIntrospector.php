<?php

namespace App\Services\Datasets;

use PDO;

/**
 * Déduit la description d'un jeu de données (tables, colonnes, clés, volumétrie)
 * en le chargeant dans une base SQLite en mémoire.
 *
 * Résultat stocké dans datasets.tables_meta et datasets.schema_diagram.
 */
class SchemaIntrospector
{
    /**
     * @return list<array{name: string, rows: int, columns: list<array{name: string, type: string, primary: bool, nullable: bool, references: ?string}>}>
     */
    public function describe(string $schemaSql, string $seedSql = ''): array
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec($schemaSql);
        $pdo->exec($seedSql);

        $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_map(function (string $table) use ($pdo) {
            $quoted = '"'.str_replace('"', '""', $table).'"';

            $foreignKeys = [];
            foreach ($pdo->query("PRAGMA foreign_key_list({$quoted})")->fetchAll(PDO::FETCH_ASSOC) as $fk) {
                $foreignKeys[$fk['from']] = $fk['table'].'.'.($fk['to'] ?? 'id');
            }

            $columns = array_map(fn (array $column) => [
                'name' => $column['name'],
                'type' => strtoupper($column['type']) ?: 'ANY',
                'primary' => (bool) $column['pk'],
                'nullable' => ! $column['notnull'] && ! $column['pk'],
                'references' => $foreignKeys[$column['name']] ?? null,
            ], $pdo->query("PRAGMA table_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC));

            return [
                'name' => $table,
                'rows' => (int) $pdo->query("SELECT COUNT(*) FROM {$quoted}")->fetchColumn(),
                'columns' => $columns,
            ];
        }, $tables);
    }

    /**
     * Diagramme Mermaid (erDiagram) à partir de describe().
     *
     * @param  list<array{name: string, columns: list<array{name: string, type: string, primary: bool, references: ?string}>}>  $tables
     */
    public function mermaid(array $tables): string
    {
        $lines = ['erDiagram'];

        foreach ($tables as $table) {
            $lines[] = "    {$table['name']} {";
            foreach ($table['columns'] as $column) {
                $type = preg_replace('/[^A-Za-z0-9_]/', '_', strtolower($column['type']));
                $key = $column['primary'] ? ' PK' : ($column['references'] ? ' FK' : '');
                $lines[] = "        {$type} {$column['name']}{$key}";
            }
            $lines[] = '    }';
        }

        foreach ($tables as $table) {
            foreach ($table['columns'] as $column) {
                if ($column['references']) {
                    [$parent] = explode('.', $column['references']);
                    $lines[] = "    {$parent} ||--o{ {$table['name']} : \"{$column['name']}\"";
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Schéma au format attendu par l'autocomplétion de CodeMirror : {table: [colonnes]}.
     *
     * @param  list<array{name: string, columns: list<array{name: string}>}>  $tables
     * @return array<string, list<string>>
     */
    public function completionSchema(array $tables): array
    {
        return collect($tables)
            ->mapWithKeys(fn (array $table) => [$table['name'] => array_column($table['columns'], 'name')])
            ->all();
    }
}
