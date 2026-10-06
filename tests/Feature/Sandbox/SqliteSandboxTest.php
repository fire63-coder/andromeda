<?php

namespace Tests\Feature\Sandbox;

use App\Models\Dataset;
use App\Models\SqlDialect;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

class SqliteSandboxTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private SandboxManager $sandbox;

    private Dataset $dataset;

    private SqlDialect $sqlite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

        $this->sandbox = app(SandboxManager::class);
        $this->dataset = Dataset::where('slug', 'boutique')->firstOrFail();
        $this->sqlite = SqlDialect::where('slug', 'sqlite')->firstOrFail();
    }

    #[Test]
    public function it_runs_a_select_and_returns_columns_and_rows(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->sqlite, "SELECT name FROM customers WHERE city = 'Lyon' ORDER BY name");

        $this->assertTrue($result->success);
        $this->assertSame(['name'], $result->columns);
        $this->assertSame([['Alice Martin'], ['Chloé Durand'], ['Gaëlle Roux']], $result->rows);
    }

    #[Test]
    public function modifications_are_never_persisted(): void
    {
        $update = $this->sandbox->run(
            $this->dataset,
            $this->sqlite,
            'DELETE FROM order_items',
            ['allowed_statements' => ['dml']],
            checkQueries: ['items' => 'SELECT COUNT(*) FROM order_items'],
        );

        $this->assertSame(13, $update->affectedRows);
        $this->assertSame([[0]], $update->checks['items']['rows']);

        $after = $this->sandbox->run($this->dataset, $this->sqlite, 'SELECT COUNT(*) FROM order_items');
        $this->assertSame([[13]], $after->rows);
    }

    #[Test]
    public function sql_errors_are_reported_without_sqlstate_noise(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->sqlite, 'SELECT nom FROM customers');

        $this->assertFalse($result->success);
        $this->assertSame(QueryResult::ERROR_SQL, $result->errorType);
        $this->assertSame('no such column: nom', $result->error);
    }

    #[Test]
    public function long_running_queries_are_killed(): void
    {
        $result = $this->sandbox->run(
            $this->dataset,
            $this->sqlite,
            'WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r) SELECT COUNT(*) FROM r',
            timeoutMs: 500,
        );

        $this->assertSame(QueryResult::ERROR_TIMEOUT, $result->errorType);
    }

    #[Test]
    public function results_are_truncated_to_the_row_limit(): void
    {
        config(['sandbox.max_rows' => 10]);

        $result = $this->sandbox->run($this->dataset, $this->sqlite, 'WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r) SELECT n FROM r');

        $this->assertTrue($result->success);
        $this->assertCount(10, $result->rows);
        $this->assertTrue($result->truncated);
    }

    #[Test]
    public function rejected_queries_never_reach_the_engine(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->sqlite, "ATTACH DATABASE '/tmp/x.sqlite' AS x");

        $this->assertSame(QueryResult::ERROR_REJECTED, $result->errorType);
        $this->assertFileDoesNotExist('/tmp/x.sqlite');
    }

    #[Test]
    public function a_malicious_dataset_script_is_refused_before_being_built(): void
    {
        $target = sys_get_temp_dir().'/andromeda-attach-'.getmypid().'.sqlite';
        $dataset = Dataset::create(['name' => 'Piégé', 'slug' => 'piege', 'source_format' => 'sql_dump', 'status' => 'ready']);
        $dataset->builds()->create([
            'sql_dialect_id' => $this->sqlite->id,
            'schema_sql' => "CREATE TABLE t (x TEXT); ATTACH DATABASE '{$target}' AS evil; CREATE TABLE evil.payload (x TEXT);",
            'status' => 'ready',
        ]);

        $result = $this->sandbox->run($dataset, $this->sqlite, 'SELECT * FROM t');

        $this->assertSame(QueryResult::ERROR_INTERNAL, $result->errorType);
        $this->assertStringContainsString('refusé', $result->error);
        $this->assertFileDoesNotExist($target);
    }
}
