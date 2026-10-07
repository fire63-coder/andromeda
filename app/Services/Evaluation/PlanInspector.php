<?php

namespace App\Services\Evaluation;

use App\Models\SqlDialect;

/**
 * Lit le plan d'exécution d'une requête et indique, pour chaque table, si elle est atteinte
 * par une recherche dans un index ou parcourue en entier.
 *
 * Un parcours complet d'index (SQLite « SCAN t USING INDEX », PostgreSQL « Index Scan » sans
 * « Index Cond ») ne compte pas : seul un accès ciblé par la condition est une recherche indexée.
 */
class PlanInspector
{
    private const PLAN = '__plan';

    public function supports(SqlDialect $dialect): bool
    {
        return in_array($dialect->slug, ['sqlite', 'pgsql'], true);
    }

    /**
     * Requêtes à exécuter après le code de l'élève, dans la même transaction.
     *
     * @return array<string, string>
     */
    public function checkQueries(SqlDialect $dialect, string $query): array
    {
        $query = rtrim(trim($query), ";\n\t ");

        return match ($dialect->slug) {
            'sqlite' => [self::PLAN => "EXPLAIN QUERY PLAN {$query}"],
            // Sur des tables de quelques lignes, PostgreSQL préfère toujours lire toute la table :
            // on lui interdit le parcours séquentiel pour révéler s'il existe un index utilisable.
            'pgsql' => ['__plan_setup' => 'SET LOCAL enable_seqscan = off', self::PLAN => "EXPLAIN (FORMAT JSON) {$query}"],
            default => throw new \InvalidArgumentException("Plans d'exécution non pris en charge pour {$dialect->slug}."),
        };
    }

    /**
     * @param  array<string, array{columns: list<string>, rows: list<list<mixed>>}>  $checks
     * @return list<array{table: string, indexed: bool, detail: string}>
     */
    public function accesses(SqlDialect $dialect, array $checks, string $query): array
    {
        $rows = $checks[self::PLAN]['rows'] ?? [];

        return match ($dialect->slug) {
            'sqlite' => $this->sqliteAccesses($rows, $this->aliases($query)),
            'pgsql' => $this->postgresAccesses($rows),
            default => [],
        };
    }

    /**
     * Lignes « detail » de EXPLAIN QUERY PLAN : « SCAN e », « SEARCH e USING INDEX ix (manager_id=?) ».
     *
     * @param  list<list<mixed>>  $rows
     * @param  array<string, string>  $aliases  alias en minuscules => table
     * @return list<array{table: string, indexed: bool, detail: string}>
     */
    private function sqliteAccesses(array $rows, array $aliases): array
    {
        $accesses = [];

        foreach ($rows as $row) {
            $detail = (string) end($row);

            if (! preg_match('/^(SCAN|SEARCH)\s+(?:TABLE\s+)?(\S+)(?:\s+AS\s+(\S+))?/i', $detail, $match)) {
                continue; // USE TEMP B-TREE FOR ORDER BY, CO-ROUTINE...
            }

            $name = strtolower($match[3] ?? $match[2]);
            $accesses[] = [
                'table' => $aliases[$name] ?? strtolower($match[2]),
                'indexed' => strtoupper($match[1]) === 'SEARCH',
                'detail' => $detail,
            ];
        }

        return $accesses;
    }

    /**
     * @param  list<list<mixed>>  $rows  une ligne, une colonne : le plan JSON
     * @return list<array{table: string, indexed: bool, detail: string}>
     */
    private function postgresAccesses(array $rows): array
    {
        $plan = json_decode((string) ($rows[0][0] ?? ''), true);
        $accesses = [];

        $walk = function (array $node) use (&$walk, &$accesses): void {
            $type = $node['Node Type'] ?? '';

            if (isset($node['Relation Name'])) {
                $indexed = match ($type) {
                    'Index Scan', 'Index Only Scan' => isset($node['Index Cond']),
                    'Bitmap Heap Scan' => true, // toujours alimenté par un Bitmap Index Scan ciblé
                    default => false,
                };

                $accesses[] = [
                    'table' => strtolower($node['Relation Name']),
                    'indexed' => $indexed,
                    'detail' => trim($type.(isset($node['Index Name']) ? " using {$node['Index Name']}" : '')
                        ." on {$node['Relation Name']}"
                        .(isset($node['Index Cond']) ? " (Index Cond: {$node['Index Cond']})" : '')
                        .(isset($node['Filter']) ? " (Filter: {$node['Filter']})" : '')),
                ];
            }

            foreach ($node['Plans'] ?? [] as $child) {
                $walk($child);
            }
        };

        if (is_array($plan[0]['Plan'] ?? null)) {
            $walk($plan[0]['Plan']);
        }

        return $accesses;
    }

    /**
     * Alias déclarés dans FROM / JOIN : « employees e », « employees AS e ».
     *
     * @return array<string, string>
     */
    private function aliases(string $query): array
    {
        $keywords = ['WHERE', 'JOIN', 'INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS', 'ON', 'USING', 'GROUP', 'ORDER', 'LIMIT', 'NATURAL', 'UNION', 'HAVING', 'WINDOW'];
        $aliases = [];

        preg_match_all('/\b(?:FROM|JOIN)\s+([A-Za-z_]\w*)(?:\s+(?:AS\s+)?([A-Za-z_]\w*))?/i', $query, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $table = strtolower($match[1]);
            $aliases[$table] = $table;

            if (isset($match[2]) && ! in_array(strtoupper($match[2]), $keywords, true)) {
                $aliases[strtolower($match[2])] = $table;
            }
        }

        return $aliases;
    }
}
