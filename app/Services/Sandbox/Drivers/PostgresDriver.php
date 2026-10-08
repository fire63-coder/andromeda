<?php

namespace App\Services\Sandbox\Drivers;

use App\Models\DatasetBuild;
use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use App\Services\Sandbox\GuardedQuery;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\Scenario\PostgresScenarioRunner;
use App\Services\Sandbox\SqlLexer;
use App\Services\Sandbox\StatementKind;
use PDO;
use PDOException;
use PDOStatement;
use Symfony\Component\Process\Process;

/**
 * Un schéma PostgreSQL par build de jeu de données, sur un serveur dédié.
 *
 * Les requêtes des élèves s'exécutent avec un compte sans privilèges, dans une
 * transaction avec statement_timeout / lock_timeout, toujours annulée (ROLLBACK) :
 * DML et DDL sont transactionnels en PostgreSQL, rien n'est jamais persisté.
 * Les SELECT passent par un curseur pour ne rapatrier que max_rows lignes.
 */
class PostgresDriver implements SandboxDriver
{
    private const CURSOR_WORDS = ['SELECT', 'WITH', 'VALUES', 'TABLE'];

    /** @var array<string, true> schémas déjà vérifiés dans ce processus */
    private array $prepared = [];

    /**
     * @param  array{host: string, port: string, database: string, owner_username: string, owner_password: string, runner_username: string, runner_password: string, lock_timeout_ms: int}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly SqlLexer $lexer = new SqlLexer,
    ) {}

    public function prepare(DatasetBuild $build): string
    {
        $schema = $this->schemaName($build);

        if (isset($this->prepared[$schema])) {
            return $schema;
        }

        $pdo = $this->connect($this->config['owner_username'], $this->config['owner_password']);

        try {
            $pdo->beginTransaction();
            // Sérialise les provisionnements concurrents du même build.
            $pdo->query('SELECT pg_advisory_xact_lock(hashtext('.$pdo->quote($schema).'))');

            $exists = $pdo->query('SELECT 1 FROM pg_namespace WHERE nspname = '.$pdo->quote($schema))->fetchColumn();

            if (! $exists) {
                $runner = $this->quoteIdentifier($this->config['runner_username']);
                $quotedSchema = $this->quoteIdentifier($schema);

                $pdo->exec("CREATE SCHEMA {$quotedSchema}");
                $pdo->exec("SET LOCAL search_path TO {$quotedSchema}");
                $pdo->exec($build->validatedScript());

                $pdo->exec("GRANT USAGE, CREATE ON SCHEMA {$quotedSchema} TO {$runner}");
                $pdo->exec("GRANT SELECT, INSERT, UPDATE, DELETE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA {$quotedSchema} TO {$runner}");
                $pdo->exec("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA {$quotedSchema} TO {$runner}");
            }

            $pdo->commit();
            $this->prepared[$schema] = true;
        } catch (PDOException|SandboxUnavailable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw new SandboxUnavailable("Impossible de provisionner le schéma PostgreSQL {$schema} : {$e->getMessage()}", previous: $e);
        }

        return $schema;
    }

    public function execute(
        DatasetBuild $build,
        GuardedQuery $query,
        int $timeoutMs,
        int $maxRows,
        array $checkQueries = [],
    ): QueryResult {
        $schema = $this->prepare($build);
        $pdo = $this->connect($this->config['runner_username'], $this->config['runner_password']);
        $started = hrtime(true);
        $elapsed = fn () => (int) ((hrtime(true) - $started) / 1_000_000);

        $result = ['columns' => [], 'rows' => [], 'truncated' => false, 'affected_rows' => null];
        $checks = [];
        $watchdog = $query->has(StatementKind::Routine) ? $this->startWatchdog($pdo, $timeoutMs) : null;

        try {
            $pdo->exec('BEGIN');
            $pdo->exec('SET LOCAL statement_timeout = '.max(1, $timeoutMs));
            $pdo->exec('SET LOCAL lock_timeout = '.(int) $this->config['lock_timeout_ms']);
            $pdo->exec('SET LOCAL search_path TO '.$this->quoteIdentifier($schema));

            $sessionCopies = $this->needsOwnedTables($query);

            if ($sessionCopies) {
                $this->copyTablesToSession($pdo, $schema);
            }

            foreach ($query->statements as $index => $statement) {
                // PostgreSQL ne cherche jamais les fonctions dans pg_temp : elles sont créées dans le
                // schéma du build, et leurs requêtes liront quand même les copies à l'exécution.
                if ($sessionCopies && $query->kinds[$index] === StatementKind::Routine) {
                    $pdo->exec('SET LOCAL search_path TO '.$this->quoteIdentifier($schema));
                    $pdo->exec($statement);
                    $pdo->exec('SET LOCAL search_path TO pg_temp, '.$this->quoteIdentifier($schema));

                    continue;
                }

                if ($query->kinds[$index] === StatementKind::Select
                    && in_array($this->lexer->firstWord($statement), self::CURSOR_WORDS, true)) {
                    $result = [...$result, ...$this->fetchThroughCursor($pdo, $statement, $maxRows)];

                    continue;
                }

                $executed = $pdo->query($statement);

                if ($executed->columnCount() > 0) {
                    $result = [...$result, ...$this->fetch($executed, $maxRows)];
                } else {
                    $result['affected_rows'] = ($result['affected_rows'] ?? 0) + $executed->rowCount();
                }
            }

            $duration = $elapsed();

            foreach ($checkQueries as $name => $check) {
                $fetched = $this->fetch($pdo->query($check), $maxRows);
                $checks[$name] = ['columns' => $fetched['columns'], 'rows' => $fetched['rows']];
            }
        } catch (PDOException $e) {
            $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
            $connectionLost = $sqlState === '57P01' || str_starts_with($sqlState, '08') || str_contains($e->getMessage(), 'server closed the connection');

            if ($this->stopWatchdog($watchdog, $connectionLost)) {
                return QueryResult::failure("La requête a dépassé le temps limite de {$timeoutMs} ms.", QueryResult::ERROR_TIMEOUT, $elapsed());
            }

            return $this->failure($e, $timeoutMs, $elapsed());
        } finally {
            $this->stopWatchdog($watchdog);

            try {
                $pdo->exec('ROLLBACK');
            } catch (PDOException) {
                // Connexion déjà perdue (ou coupée par le chien de garde) : PostgreSQL a tout annulé.
            }
        }

        return new QueryResult(
            success: true,
            columns: $result['columns'],
            rows: $result['rows'],
            truncated: $result['truncated'],
            affectedRows: $result['affected_rows'],
            durationMs: $duration,
            checks: $checks,
        );
    }

    /**
     * CREATE INDEX, ALTER TABLE, DROP TABLE, TRUNCATE... exigent d'être propriétaire de la table,
     * ce que le compte d'exécution n'est pas (et ne doit pas être : il lirait les jeux cachés).
     */
    private function needsOwnedTables(GuardedQuery $query): bool
    {
        foreach ($query->statements as $statement) {
            if (preg_match('/^\s*(CREATE\s+(UNIQUE\s+)?INDEX|ALTER\s+(TABLE|INDEX)|DROP\s+(TABLE|INDEX)|TRUNCATE|COMMENT\s+ON|CLUSTER|REINDEX)\b/i', $statement)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copie les tables du build dans le schéma temporaire de la session, placé en tête du
     * search_path : l'élève en est propriétaire, et tout disparaît avec le ROLLBACK.
     * Les index et contraintes sont copiés, pas les clés étrangères.
     */
    private function copyTablesToSession(PDO $pdo, string $schema): void
    {
        $quotedSchema = $this->quoteIdentifier($schema);
        $tables = $pdo->query('SELECT c.oid, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE n.nspname = '.$pdo->quote($schema)." AND c.relkind = 'r' ORDER BY c.relname")->fetchAll(PDO::FETCH_NUM);

        foreach ($tables as [$oid, $table]) {
            $quotedTable = $this->quoteIdentifier($table);
            $columns = implode(', ', array_map(
                fn (string $column) => $this->quoteIdentifier($column),
                $pdo->query('SELECT attname FROM pg_attribute WHERE attrelid = '.(int) $oid
                    ." AND attnum > 0 AND NOT attisdropped AND attgenerated = '' ORDER BY attnum")->fetchAll(PDO::FETCH_COLUMN),
            ));

            $pdo->exec("CREATE TEMPORARY TABLE {$quotedTable} (LIKE {$quotedSchema}.{$quotedTable} INCLUDING ALL)");
            $pdo->exec("INSERT INTO pg_temp.{$quotedTable} ({$columns}) OVERRIDING SYSTEM VALUE SELECT {$columns} FROM {$quotedSchema}.{$quotedTable}");
        }

        $pdo->exec("SET LOCAL search_path TO pg_temp, {$quotedSchema}");
    }

    public function supportsDdl(): bool
    {
        return true; // DDL transactionnel.
    }

    /**
     * Scénario de concurrence (plusieurs sessions entrelacées) sur une copie jetable du build.
     *
     * @param  list<array{session: string, sql: string, statements: list<string>}>  $steps
     * @param  array<string, string>  $checkQueries
     */
    public function runScenario(DatasetBuild $build, array $steps, array $checkQueries, int $timeoutMs): QueryResult
    {
        $schema = $this->prepare($build);

        return (new PostgresScenarioRunner($this->config, fn (string $user, string $password) => $this->connect($user, $password)))
            ->run($schema, $steps, $checkQueries, $timeoutMs);
    }

    /**
     * Supprime les copies de scénario orphelines (« sc_<horodatage>_… ») plus anciennes que le délai.
     */
    public function purgeScenarioSchemas(int $olderThanSeconds = 600): int
    {
        $pdo = $this->connect($this->config['owner_username'], $this->config['owner_password']);
        $prefix = ($this->config['schema_prefix'] ?? '').'sc_';
        $dropped = 0;

        foreach ($pdo->query('SELECT nspname FROM pg_namespace WHERE starts_with(nspname, '.$pdo->quote($prefix).')')->fetchAll(PDO::FETCH_COLUMN) as $schema) {
            $createdAt = (int) explode('_', substr($schema, strlen($prefix)))[0];

            if ($createdAt > 0 && $createdAt < time() - $olderThanSeconds) {
                $pdo->exec('DROP SCHEMA IF EXISTS '.$this->quoteIdentifier($schema).' CASCADE');
                $dropped++;
            }
        }

        return $dropped;
    }

    public function supportsRoutines(): bool
    {
        return true; // PL/pgSQL et SQL, sous surveillance du chien de garde.
    }

    /**
     * Le code stocké peut intercepter l'annulation de statement_timeout : un processus séparé
     * coupera la connexion (pg_terminate_backend) si elle dépasse le délai d'une seconde.
     */
    private function startWatchdog(PDO $pdo, int $timeoutMs): Process
    {
        // Identifiants transmis par l'environnement (lisible par le seul utilisateur système),
        // jamais en argument (visible dans ps). Pas par stdin : Symfony ne l'écrit qu'au fil des
        // appels à Process, or le parent reste bloqué dans PDO pendant toute la requête.
        $process = new Process([
            $this->config['php_binary'] ?? PHP_BINARY,
            '-d', 'display_errors=stderr',
            __DIR__.'/../Runners/pg-watchdog.php',
        ], env: ['SANDBOX_WATCHDOG' => json_encode([
            'dsn' => $this->dsn(),
            'username' => $this->config['owner_username'],
            'password' => $this->config['owner_password'],
            'pid' => (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn(),
            'delay_ms' => $timeoutMs + 1000,
        ])]);
        $process->setTimeout(null);
        $process->start();

        return $process;
    }

    /**
     * Arrête le chien de garde. Retourne vrai s'il a dû couper la connexion.
     */
    private function stopWatchdog(?Process $watchdog, bool $connectionLost = false): bool
    {
        if (! $watchdog) {
            return false;
        }

        // Connexion coupée : laisse au chien de garde le temps de confirmer que c'est lui.
        $deadline = microtime(true) + ($connectionLost ? 1.0 : 0);
        while ($watchdog->isRunning() && microtime(true) < $deadline && ! str_contains($watchdog->getOutput(), 'terminated')) {
            usleep(10_000);
        }

        $terminated = str_contains($watchdog->getOutput(), 'terminated');

        if ($watchdog->isRunning()) {
            $watchdog->stop(0);
        }

        return $terminated;
    }

    public function destroy(DatasetBuild $build): void
    {
        $pdo = $this->connect($this->config['owner_username'], $this->config['owner_password']);
        $schema = $this->schemaName($build);
        $pdo->exec('DROP SCHEMA IF EXISTS '.$this->quoteIdentifier($schema).' CASCADE');
        unset($this->prepared[$schema]);
    }

    /**
     * Crée (ou met à jour) les comptes propriétaire et d'exécution, et verrouille la base sandbox.
     * Seule opération qui nécessite un superutilisateur : `php artisan sandbox:setup-pgsql`.
     */
    public function installRoles(string $superuser, string $superuserPassword): void
    {
        $pdo = $this->connect($superuser, $superuserPassword);
        $database = $this->quoteIdentifier($this->config['database']);
        $owner = $this->quoteIdentifier($this->config['owner_username']);
        $runner = $this->quoteIdentifier($this->config['runner_username']);

        $this->upsertRole($pdo, $this->config['owner_username'], $this->config['owner_password']);
        $this->upsertRole($pdo, $this->config['runner_username'], $this->config['runner_password']);

        // Filets de sécurité côté serveur, indépendants des requêtes.
        $pdo->exec("ALTER ROLE {$owner} SET statement_timeout = '120s'");
        $pdo->exec("ALTER ROLE {$runner} SET statement_timeout = '15s'");
        $pdo->exec("ALTER ROLE {$runner} SET idle_in_transaction_session_timeout = '30s'");
        $pdo->exec("ALTER ROLE {$runner} SET work_mem = '16MB'");
        $pdo->exec("ALTER ROLE {$runner} SET temp_file_limit = '64MB'");

        $pdo->exec("REVOKE ALL ON DATABASE {$database} FROM PUBLIC");
        $pdo->exec("GRANT CONNECT, CREATE, TEMPORARY ON DATABASE {$database} TO {$owner}");
        $pdo->exec("GRANT CONNECT, TEMPORARY ON DATABASE {$database} TO {$runner}");
        $pdo->exec('REVOKE CREATE ON SCHEMA public FROM PUBLIC');
        // Empêche de lever statement_timeout via SELECT set_config(...), y compris en SQL dynamique.
        $pdo->exec('REVOKE EXECUTE ON FUNCTION set_config(text, text, boolean) FROM PUBLIC');
        // Un compte non privilégié peut annuler ou couper les connexions de son propre rôle :
        // l'exécution partagée par tous les élèves ne doit pas pouvoir couper celles des autres.
        foreach (['pg_cancel_backend(integer)', 'pg_terminate_backend(integer, bigint)'] as $function) {
            $pdo->exec("REVOKE EXECUTE ON FUNCTION {$function} FROM PUBLIC");
        }
        // Le chien de garde coupe les connexions d'exécution trop longues : le compte propriétaire
        // (NOINHERIT) endosse explicitement le rôle pg_signal_backend (SET ROLE) le temps de le faire.
        $pdo->exec('GRANT EXECUTE ON FUNCTION pg_terminate_backend(integer, bigint) TO pg_signal_backend');
        $pdo->exec('GRANT pg_signal_backend TO '.$owner);
    }

    private function upsertRole(PDO $pdo, string $name, string $password): void
    {
        $exists = $pdo->query('SELECT 1 FROM pg_roles WHERE rolname = '.$pdo->quote($name))->fetchColumn();

        $pdo->exec(($exists ? 'ALTER' : 'CREATE').' ROLE '.$this->quoteIdentifier($name)
            .' LOGIN PASSWORD '.$pdo->quote($password)
            .' NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT NOREPLICATION NOBYPASSRLS CONNECTION LIMIT 50');
    }

    private function schemaName(DatasetBuild $build): string
    {
        $version = substr(md5($build->updated_at?->toIso8601String().$build->schema_sql), 0, 10);

        return ($this->config['schema_prefix'] ?? '')."ds{$build->dataset_id}_b{$build->id}_{$version}";
    }

    /**
     * @return array{columns: list<string>, rows: list<list<mixed>>, truncated: bool}
     */
    private function fetchThroughCursor(PDO $pdo, string $statement, int $maxRows): array
    {
        $pdo->exec("DECLARE sandbox_cursor NO SCROLL CURSOR FOR {$statement}");
        $fetched = $this->fetch($pdo->query('FETCH FORWARD '.($maxRows + 1).' FROM sandbox_cursor'), $maxRows);
        $pdo->exec('CLOSE sandbox_cursor');

        return $fetched;
    }

    /**
     * @return array{columns: list<string>, rows: list<list<mixed>>, truncated: bool}
     */
    private function fetch(PDOStatement $statement, int $maxRows): array
    {
        $columns = [];
        for ($i = 0; $i < $statement->columnCount(); $i++) {
            $columns[] = $statement->getColumnMeta($i)['name'] ?? "col{$i}";
        }

        $rows = [];
        $truncated = false;
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            if (count($rows) >= $maxRows) {
                $truncated = true;
                break;
            }
            $rows[] = array_map(fn ($value) => is_resource($value) ? '0x'.strtoupper(bin2hex(stream_get_contents($value))) : $value, $row);
        }
        $statement->closeCursor();

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    private function failure(PDOException $e, int $timeoutMs, int $durationMs): QueryResult
    {
        $sqlState = $e->errorInfo[0] ?? $e->getCode();

        if ($sqlState === '57014') {
            return QueryResult::failure("La requête a dépassé le temps limite de {$timeoutMs} ms.", QueryResult::ERROR_TIMEOUT, $durationMs);
        }

        if ($sqlState === '55P03') {
            return QueryResult::failure('Les données sont momentanément verrouillées, réessayez dans un instant.', QueryResult::ERROR_TIMEOUT, $durationMs);
        }

        // "SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "x" does not exist\nLINE 1: ...\n  ^\nHINT: ..."
        // On garde le message et ses HINT / DETAIL ; l'extrait "LINE n" citerait le curseur interne.
        $message = preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ (ERROR:\s+)?/', '', $e->getMessage());
        $lines = array_filter(
            explode("\n", $message),
            fn (string $line) => ! str_starts_with($line, 'LINE ') && trim($line, " ^\t") !== '',
        );

        return QueryResult::failure(
            preg_replace('/^(HINT|DETAIL):\s+/m', '$1 : ', trim(implode("\n", $lines))),
            QueryResult::ERROR_SQL,
            $durationMs,
        );
    }

    private function connect(string $username, string $password): PDO
    {
        if (! extension_loaded('pdo_pgsql')) {
            throw new SandboxUnavailable('L\'extension PHP pdo_pgsql n\'est pas activée : décommentez « extension=pdo_pgsql » dans le php.ini (voir « php --ini »), puis relancez PHP.');
        }

        try {
            return new PDO(
                $this->dsn(),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            throw new SandboxUnavailable('Le serveur PostgreSQL du bac à sable est injoignable.', previous: $e);
        }
    }

    private function dsn(): string
    {
        return "pgsql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']};connect_timeout=3";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
