<?php

namespace Tests\Feature\Sandbox;

use App\Models\Dataset;
use App\Models\SqlDialect;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use mysqli;
use mysqli_sql_exception;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

/**
 * Nécessite un serveur MySQL de sandbox (variables SANDBOX_MYSQL_*) ; ignoré sinon.
 */
class MysqlSandboxTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    /** Requête lente sans fonction interdite : produit cartésien d'une CTE récursive. */
    private const SLOW = 'WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 100000) SELECT COUNT(*) FROM r a, r b';

    private SandboxManager $sandbox;

    private Dataset $dataset;

    private SqlDialect $mysql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        try {
            $this->runner()->close();
        } catch (mysqli_sql_exception) {
            $this->markTestSkipped('Serveur MySQL de sandbox indisponible.');
        }

        $this->setUpSandbox();
        $this->sandbox = app(SandboxManager::class);
        $this->dataset = Dataset::where('slug', 'boutique')->firstOrFail();
        $this->mysql = SqlDialect::where('slug', 'mysql')->firstOrFail();
    }

    private function runner(): mysqli
    {
        $config = config('sandbox.drivers.mysql');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        return new mysqli($config['host'], $config['runner_username'], $config['runner_password'], null, (int) $config['port']);
    }

    #[Test]
    public function it_runs_selects_with_native_types(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->mysql, "SELECT id, name, price FROM products WHERE category = 'Livres' ORDER BY id");

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame([1, 'SQL pour les nuls', '24.90'], $result->rows[0]);
    }

    #[Test]
    public function modifications_are_rolled_back(): void
    {
        $delete = $this->sandbox->run($this->dataset, $this->mysql, 'DELETE FROM order_items', ['allowed_statements' => ['dml']]);

        $this->assertSame(13, $delete->affectedRows);
        $this->assertSame([[13]], $this->sandbox->run($this->dataset, $this->mysql, 'SELECT COUNT(*) FROM order_items')->rows);
    }

    #[Test]
    public function slow_selects_are_stopped_by_the_server(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->mysql, self::SLOW, timeoutMs: 500);

        $this->assertSame(QueryResult::ERROR_TIMEOUT, $result->errorType);
    }

    #[Test]
    public function slow_modifications_are_killed_by_the_watchdog(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->mysql, 'UPDATE products SET stock = ('.self::SLOW.')', ['allowed_statements' => ['dml']], 500);

        $this->assertSame(QueryResult::ERROR_TIMEOUT, $result->errorType);
        $this->assertLessThan(3000, $result->durationMs);
        $this->assertSame([[40]], $this->sandbox->run($this->dataset, $this->mysql, 'SELECT stock FROM products WHERE id = 1')->rows);
    }

    #[Test]
    public function large_results_are_truncated_without_reading_everything(): void
    {
        config(['sandbox.max_rows' => 50]);

        $result = $this->sandbox->run($this->dataset, $this->mysql, 'WITH RECURSIVE r(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM r WHERE n < 900000) SELECT n FROM r');

        $this->assertCount(50, $result->rows);
        $this->assertTrue($result->truncated);
    }

    #[Test]
    public function ddl_is_refused_because_it_would_commit_implicitly(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->mysql, 'CREATE TABLE notes (id INT)', ['allowed_statements' => ['ddl']]);

        $this->assertSame(QueryResult::ERROR_REJECTED, $result->errorType);
        $this->assertStringContainsString('valident implicitement la transaction', $result->error);
    }

    #[Test]
    public function the_runner_account_cannot_escape_the_sandbox(): void
    {
        $conn = $this->runner();

        foreach (['SELECT * FROM mysql.user', 'CREATE DATABASE evil', "SELECT 'x' INTO OUTFILE '/tmp/andromeda-out'", 'SET GLOBAL max_connections = 1'] as $sql) {
            try {
                $conn->query($sql);
                $this->fail("Le compte d'exécution a pu lancer : {$sql}");
            } catch (mysqli_sql_exception $e) {
                $this->assertMatchesRegularExpression('/denied|privilege/i', $e->getMessage());
            }
        }
    }
}
