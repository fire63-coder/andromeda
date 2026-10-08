<?php

namespace Tests\Feature\Sandbox;

use App\Enums\SubmissionStatus;
use App\Models\Dataset;
use App\Models\Exercise;
use App\Models\SqlDialect;
use App\Services\Evaluation\SubmissionEvaluator;
use App\Services\Sandbox\SandboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\UsesSandbox;
use Tests\TestCase;

/**
 * Une requête exécutée sur un jeu de données ne doit jamais pouvoir lire un autre jeu,
 * en particulier les jeux de test cachés (sinon un élève pourrait y adapter sa réponse).
 */
class HiddenDatasetIsolationTest extends TestCase
{
    use RefreshDatabase;
    use UsesSandbox;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->setUpSandbox();
    }

    private function attack(string $slug, \Closure $qualified): void
    {
        $sandbox = app(SandboxManager::class);
        $dialect = SqlDialect::where('slug', $slug)->firstOrFail();
        $visible = Dataset::where('slug', 'boutique')->firstOrFail();
        $hidden = Dataset::where('slug', 'boutique-tests')->firstOrFail();

        if (! $sandbox->isExecutable($visible, $dialect)) {
            $this->markTestSkipped("Serveur {$slug} de sandbox indisponible.");
        }

        // Les deux jeux sont matérialisés, comme lors d'une correction.
        $this->assertTrue($sandbox->run($hidden, $dialect, 'SELECT COUNT(*) FROM products')->success);
        $this->assertTrue($sandbox->run($visible, $dialect, 'SELECT COUNT(*) FROM products')->success);

        $resource = $sandbox->driver($dialect)->prepare($sandbox->build($hidden, $dialect));
        $result = $sandbox->run($visible, $dialect, 'SELECT name FROM '.$qualified($resource).' ORDER BY id');

        $this->assertFalse($result->success, "Le jeu caché est lisible depuis le jeu visible ({$slug}) : ".json_encode($result->rows));
    }

    #[Test]
    public function postgresql_queries_cannot_read_another_dataset(): void
    {
        $this->attack('pgsql', fn (string $schema) => '"'.$schema.'".products');
    }

    #[Test]
    public function mysql_queries_cannot_read_another_dataset(): void
    {
        $this->attack('mysql', fn (string $database) => '`'.$database.'`.products');
    }

    #[Test]
    public function the_shared_execution_accounts_have_no_direct_access_to_datasets(): void
    {
        $sandbox = app(SandboxManager::class);
        $hidden = Dataset::where('slug', 'boutique-tests')->firstOrFail();
        $checked = 0;

        $pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();
        if ($sandbox->isExecutable($hidden, $pgsql)) {
            $schema = $sandbox->driver($pgsql)->prepare($sandbox->build($hidden, $pgsql));
            $config = config('sandbox.drivers.pgsql');
            $pdo = new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['runner_username'], $config['runner_password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

            try {
                $pdo->query('SELECT COUNT(*) FROM "'.$schema.'".products');
                $this->fail('Le compte d\'exécution PostgreSQL partagé lit un jeu de données.');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('permission denied', $e->getMessage());
            }
            $checked++;
        }

        $mysql = SqlDialect::where('slug', 'mysql')->firstOrFail();
        if ($sandbox->isExecutable($hidden, $mysql)) {
            $database = $sandbox->driver($mysql)->prepare($sandbox->build($hidden, $mysql));
            $config = config('sandbox.drivers.mysql');
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $conn = new \mysqli($config['host'], $config['runner_username'], $config['runner_password'], null, (int) $config['port']);

            try {
                $conn->query("SELECT COUNT(*) FROM `{$database}`.products");
                $this->fail('Le compte d\'exécution MySQL lit un jeu de données sans activer son rôle.');
            } catch (\mysqli_sql_exception $e) {
                $this->assertStringContainsString('denied', $e->getMessage());
            }
            $checked++;
        }

        if ($checked === 0) {
            $this->markTestSkipped('Aucun serveur de sandbox disponible.');
        }
    }

    #[Test]
    public function a_temporary_table_cannot_fake_the_state_read_by_control_queries(): void
    {
        $pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();
        $exercise = Exercise::where('slug', 'trigger-audit-salaires')->firstOrFail();

        if (! app(SandboxManager::class)->isExecutable($exercise->datasets->first(), $pgsql)) {
            $this->markTestSkipped('Serveur PostgreSQL de sandbox indisponible.');
        }

        $result = app(SubmissionEvaluator::class)->evaluate($exercise, $pgsql,
            'CREATE TEMP TABLE salary_audit AS SELECT id AS employee_id, salary AS old_salary, salary + 100 AS new_salary FROM employees WHERE department_id = 3');

        $this->assertSame(SubmissionStatus::Rejected, $result->status);
    }

    #[Test]
    public function modifications_never_wait_for_locks_held_by_other_students(): void
    {
        $sandbox = app(SandboxManager::class);
        $pgsql = SqlDialect::where('slug', 'pgsql')->firstOrFail();
        $shop = Dataset::where('slug', 'boutique')->firstOrFail();

        if (! $sandbox->isExecutable($shop, $pgsql)) {
            $this->markTestSkipped('Serveur PostgreSQL de sandbox indisponible.');
        }

        // Un autre élève tient un verrou sur la ligne 1 de la table partagée…
        $driver = $sandbox->driver($pgsql);
        $build = $sandbox->build($shop, $pgsql);
        $schema = $driver->prepare($build);
        ['username' => $user, 'password' => $password] = $driver->executionCredentials($build);
        $config = config('sandbox.drivers.pgsql');
        $other = new \PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $user, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $other->exec('BEGIN');
        $other->query('SELECT * FROM "'.$schema.'".products WHERE id = 1 FOR UPDATE')->fetchAll();

        // … la modification de cet élève travaille sur sa copie privée : pas d'attente, pas d'échec.
        $result = $sandbox->run($shop, $pgsql, 'UPDATE products SET stock = 0 WHERE id = 1', ['allowed_statements' => ['dml']]);

        $other->exec('ROLLBACK');
        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(1, $result->affectedRows);
    }
}
