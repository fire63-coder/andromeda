<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;
use mysqli;
use PDO;
use Throwable;

/**
 * Isole les ressources sandbox des tests de celles du poste de développement :
 * dossier SQLite propre au processus, schémas PostgreSQL « t_… » et bases MySQL
 * « sbx_t_… », purgés au début de chaque test.
 */
trait UsesSandbox
{
    protected function setUpSandbox(): void
    {
        $path = storage_path('framework/testing/sandbox-'.getmypid());
        File::ensureDirectoryExists($path);

        config([
            'sandbox.drivers.sqlite.path' => $path,
            'sandbox.drivers.pgsql.schema_prefix' => 't_',
            'sandbox.drivers.mysql.database_prefix' => 'sbx_t_',
        ]);

        $this->purgeServerSandboxes();
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($path));
    }

    private function purgeServerSandboxes(): void
    {
        $pg = config('sandbox.drivers.pgsql');

        try {
            $pdo = new PDO("pgsql:host={$pg['host']};port={$pg['port']};dbname={$pg['database']};connect_timeout=2", $pg['owner_username'], $pg['owner_password']);
            foreach ($pdo->query("SELECT nspname FROM pg_namespace WHERE nspname LIKE 't\\_%'")->fetchAll(PDO::FETCH_COLUMN) as $schema) {
                $pdo->exec('DROP SCHEMA "'.$schema.'" CASCADE');
            }
        } catch (Throwable) {
            // Serveur PostgreSQL absent : rien à purger.
        }

        $my = config('sandbox.drivers.mysql');

        try {
            mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
            $conn = new mysqli($my['host'], $my['owner_username'], $my['owner_password'], null, (int) $my['port']);
            foreach ($conn->query("SHOW DATABASES LIKE 'sbx\\_t\\_%'")->fetch_all() as [$database]) {
                $conn->query("DROP DATABASE `{$database}`");
            }
            $conn->close();
        } catch (Throwable) {
            // Serveur MySQL absent : rien à purger.
        }
    }
}
