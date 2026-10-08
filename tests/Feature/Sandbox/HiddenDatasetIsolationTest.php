<?php

namespace Tests\Feature\Sandbox;

use App\Models\Dataset;
use App\Models\SqlDialect;
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
}
