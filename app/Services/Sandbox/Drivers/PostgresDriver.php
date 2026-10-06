<?php

namespace App\Services\Sandbox\Drivers;

use App\Models\DatasetBuild;
use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use App\Services\Sandbox\GuardedQuery;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SqlLexer;
use App\Services\Sandbox\StatementKind;
use PDO;
use PDOException;
use PDOStatement;

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

        try {
            $pdo->exec('BEGIN');
            $pdo->exec('SET LOCAL statement_timeout = '.max(1, $timeoutMs));
            $pdo->exec('SET LOCAL lock_timeout = '.(int) $this->config['lock_timeout_ms']);
            $pdo->exec('SET LOCAL search_path TO '.$this->quoteIdentifier($schema));

            foreach ($query->statements as $index => $statement) {
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
            return $this->failure($e, $timeoutMs, $elapsed());
        } finally {
            try {
                $pdo->exec('ROLLBACK');
            } catch (PDOException) {
                // Connexion déjà perdue : rien à annuler.
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

    public function supportsDdl(): bool
    {
        return true; // DDL transactionnel.
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
        try {
            return new PDO(
                "pgsql:host={$this->config['host']};port={$this->config['port']};dbname={$this->config['database']};connect_timeout=3",
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $e) {
            throw new SandboxUnavailable('Le serveur PostgreSQL du bac à sable est injoignable.', previous: $e);
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
