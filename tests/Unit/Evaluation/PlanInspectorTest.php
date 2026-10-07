<?php

namespace Tests\Unit\Evaluation;

use App\Models\SqlDialect;
use App\Services\Evaluation\PlanInspector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PlanInspectorTest extends TestCase
{
    private function dialect(string $slug): SqlDialect
    {
        return (new SqlDialect)->forceFill(['slug' => $slug]);
    }

    private function checks(array $rows): array
    {
        return ['__plan' => ['columns' => [], 'rows' => $rows]];
    }

    #[Test]
    public function sqlite_aliases_are_resolved_and_only_searches_count(): void
    {
        $accesses = (new PlanInspector)->accesses($this->dialect('sqlite'), $this->checks([
            [3, 0, 0, 'SCAN e USING INDEX idx_employees_hired_at'],
            [7, 0, 0, 'SEARCH d USING INTEGER PRIMARY KEY (rowid=?)'],
            [9, 0, 0, 'USE TEMP B-TREE FOR ORDER BY'],
        ]), 'SELECT e.name FROM employees AS e JOIN departments d ON d.id = e.department_id ORDER BY e.name');

        $this->assertSame([
            ['table' => 'employees', 'indexed' => false, 'detail' => 'SCAN e USING INDEX idx_employees_hired_at'],
            ['table' => 'departments', 'indexed' => true, 'detail' => 'SEARCH d USING INTEGER PRIMARY KEY (rowid=?)'],
        ], $accesses);
    }

    #[Test]
    public function postgres_index_scans_without_index_condition_are_full_scans(): void
    {
        $plan = json_encode([['Plan' => [
            'Node Type' => 'Nested Loop',
            'Plans' => [
                ['Node Type' => 'Index Scan', 'Index Name' => 'employees_pkey', 'Relation Name' => 'employees', 'Filter' => '(manager_id = 5)'],
                ['Node Type' => 'Bitmap Heap Scan', 'Relation Name' => 'departments', 'Plans' => [
                    ['Node Type' => 'Bitmap Index Scan', 'Index Name' => 'idx_city', 'Index Cond' => "(city = 'Paris')"],
                ]],
                ['Node Type' => 'Index Only Scan', 'Index Name' => 'ix', 'Relation Name' => 'orders', 'Index Cond' => '(id = 1)'],
            ],
        ]]]);

        $accesses = (new PlanInspector)->accesses($this->dialect('pgsql'), $this->checks([[$plan]]), '');

        $this->assertSame(['employees' => false, 'departments' => true, 'orders' => true], array_column($accesses, 'indexed', 'table'));
    }

    #[Test]
    public function postgres_plans_are_read_with_sequential_scans_disabled(): void
    {
        $queries = (new PlanInspector)->checkQueries($this->dialect('pgsql'), "SELECT 1;\n");

        $this->assertSame('SET LOCAL enable_seqscan = off', reset($queries));
        $this->assertSame('EXPLAIN (FORMAT JSON) SELECT 1', end($queries));
    }
}
