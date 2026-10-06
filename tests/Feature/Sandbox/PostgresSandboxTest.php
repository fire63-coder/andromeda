<?php

namespace Tests\Feature\Sandbox;

use App\Models\Dataset;
use App\Models\SqlDialect;
use App\Services\Sandbox\Drivers\PostgresDriver;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Nécessite un serveur PostgreSQL de sandbox (variables SANDBOX_PGSQL_*) ; ignoré sinon.
 */
class PostgresSandboxTest extends TestCase
{
    use RefreshDatabase;

    private SandboxManager $sandbox;

    private Dataset $dataset;

    private SqlDialect $pgsql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $config = config('sandbox.drivers.pgsql');

        try {
            new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']};connect_timeout=2", $config['runner_username'], $config['runner_password']);
        } catch (PDOException) {
            $this->markTestSkipped('Serveur PostgreSQL de sandbox indisponible.');
        }

        $this->sandbox = app(SandboxManager::class);
        $this->dataset = Dataset::where('slug', 'boutique')->firstOrFail();
        $this->pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();
    }

    protected function tearDown(): void
    {
        if (isset($this->dataset)) {
            foreach ($this->dataset->builds as $build) {
                if ($build->sql_dialect_id === $this->pgsql->id) {
                    (new PostgresDriver(config('sandbox.drivers.pgsql')))->destroy($build);
                }
            }
        }

        parent::tearDown();
    }

    #[Test]
    public function it_runs_queries_through_a_cursor(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->pgsql, "SELECT name, price FROM products WHERE category = 'Livres' ORDER BY id");

        $this->assertTrue($result->success);
        $this->assertSame(['name', 'price'], $result->columns);
        $this->assertSame(['SQL pour les nuls', '24.90'], $result->rows[0]);
    }

    #[Test]
    public function modifications_are_rolled_back(): void
    {
        $this->sandbox->run($this->dataset, $this->pgsql, 'DELETE FROM order_items', ['allowed_statements' => ['dml']]);

        $this->assertSame([[13]], $this->sandbox->run($this->dataset, $this->pgsql, 'SELECT COUNT(*) FROM order_items')->rows);
    }

    #[Test]
    public function statement_timeout_is_enforced(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->pgsql, 'SELECT pg_sleep(2)', timeoutMs: 300);

        $this->assertSame(QueryResult::ERROR_TIMEOUT, $result->errorType);
    }

    #[Test]
    public function error_messages_hide_the_internal_cursor(): void
    {
        $result = $this->sandbox->run($this->dataset, $this->pgsql, 'SELECT nom FROM customers');

        $this->assertSame('column "nom" does not exist', $result->error);
    }

    #[Test]
    public function the_runner_account_cannot_escape_the_sandbox(): void
    {
        $config = config('sandbox.drivers.pgsql');
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['runner_username'], $config['runner_password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach (["SELECT set_config('statement_timeout', '0', false)", 'SELECT * FROM pg_shadow', "COPY (SELECT 1) TO '/tmp/out'"] as $sql) {
            try {
                $pdo->query($sql);
                $this->fail("Le compte d'exécution a pu lancer : {$sql}");
            } catch (PDOException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }
}
