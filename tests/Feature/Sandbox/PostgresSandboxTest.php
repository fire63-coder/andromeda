<?php

namespace Tests\Feature\Sandbox;

use App\Models\Dataset;
use App\Models\SqlDialect;
use App\Services\Sandbox\GuardedQuery;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SandboxManager;
use App\Services\Sandbox\StatementKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

/**
 * Nécessite un serveur PostgreSQL de sandbox (variables SANDBOX_PGSQL_*) ; ignoré sinon.
 */
class PostgresSandboxTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    private SandboxManager $sandbox;

    private Dataset $dataset;

    private SqlDialect $pgsql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();

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
        $result = $this->sandbox->run($this->dataset, $this->pgsql, 'SELECT COUNT(*) FROM generate_series(1, 500000000)', timeoutMs: 300);

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
        // Le compte d'exécution du build, celui qui exécute réellement les requêtes des élèves.
        $driver = $this->sandbox->driver($this->pgsql);
        $build = $this->sandbox->build($this->dataset, $this->pgsql);
        $driver->prepare($build);
        ['username' => $user, 'password' => $password] = $driver->executionCredentials($build);
        $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        foreach (["SELECT set_config('statement_timeout', '0', false)", 'SELECT * FROM pg_shadow', "COPY (SELECT 1) TO '/tmp/out'", 'SELECT pg_terminate_backend(pg_backend_pid())', 'SELECT pg_cancel_backend(pg_backend_pid())', 'SET ROLE pg_signal_backend'] as $sql) {
            try {
                $pdo->query($sql);
                $this->fail("Le compte d'exécution a pu lancer : {$sql}");
            } catch (PDOException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
        }
    }

    #[Test]
    public function stored_functions_run_and_are_rolled_back(): void
    {
        $options = ['allowed_statements' => ['routine', 'select']];
        $result = $this->sandbox->run($this->dataset, $this->pgsql, <<<'SQL'
            CREATE FUNCTION ttc(prix numeric) RETURNS numeric LANGUAGE plpgsql AS $$
            BEGIN
                RETURN ROUND(prix * 1.2, 2);
            END
            $$;
            SELECT ttc(price) FROM products WHERE id = 1;
            SQL, $options);

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame([['29.88']], $result->rows);

        $again = $this->sandbox->run($this->dataset, $this->pgsql, 'SELECT ttc(10)', $options);
        $this->assertStringContainsString('function ttc(integer) does not exist', (string) $again->error);
    }

    #[Test]
    public function the_watchdog_ends_code_that_survives_the_statement_timeout(): void
    {
        // Contourne volontairement le QueryGuard (qui refuse query_canceled) : seul le chien de garde protège.
        $driver = $this->sandbox->driver($this->pgsql);
        $query = new GuardedQuery([
            'CREATE FUNCTION f() RETURNS int LANGUAGE plpgsql AS $$ BEGIN LOOP BEGIN PERFORM pg_sleep(1); EXCEPTION WHEN query_canceled THEN NULL; END; END LOOP; END $$',
            'SELECT f()',
        ], [StatementKind::Routine, StatementKind::Select]);

        $started = microtime(true);
        $result = $driver->execute($this->sandbox->build($this->dataset, $this->pgsql), $query, 500, 10);

        $this->assertSame(QueryResult::ERROR_TIMEOUT, $result->errorType);
        $this->assertLessThan(5, microtime(true) - $started);
        $this->assertTrue($this->sandbox->run($this->dataset, $this->pgsql, 'SELECT 1')->success);
    }
}
