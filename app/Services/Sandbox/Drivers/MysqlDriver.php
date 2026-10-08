<?php

namespace App\Services\Sandbox\Drivers;

use App\Models\DatasetBuild;
use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use App\Services\Sandbox\GuardedQuery;
use App\Services\Sandbox\QueryResult;
use App\Services\Sandbox\SqlLexer;
use App\Services\Sandbox\StatementKind;
use mysqli;
use mysqli_result;
use mysqli_sql_exception;

/**
 * Une base MySQL « sbx_… » par build de jeu de données, sur un serveur dédié.
 *
 * - Compte d'exécution limité à SELECT / INSERT / UPDATE / DELETE sur les bases sbx_*.
 * - Transaction toujours annulée. Le DDL (et TRUNCATE) provoquant un COMMIT implicite
 *   en MySQL, il est refusé : il serait impossible de l'annuler.
 * - SELECT : max_execution_time côté serveur + lecture non bufferisée, arrêtée à max_rows.
 * - INSERT / UPDATE / DELETE : envoyés en asynchrone ; au-delà du délai, une seconde
 *   connexion exécute KILL QUERY (max_execution_time ne couvre pas les modifications).
 */
class MysqlDriver implements SandboxDriver
{
    private const SELECT_WORDS = ['SELECT', 'WITH', 'VALUES', 'TABLE', 'EXPLAIN'];

    /** Codes d'erreur MySQL : délai serveur dépassé, requête interrompue, verrou. */
    private const ER_QUERY_TIMEOUT = 3024;

    private const ER_QUERY_INTERRUPTED = 1317;

    private const ER_LOCK_WAIT_TIMEOUT = 1205;

    /** @var array<string, true> */
    private array $prepared = [];

    /**
     * @param  array{host: string, port: int, database_prefix: string, owner_username: string, owner_password: string, runner_username: string, runner_password: string, lock_timeout_s: int}  $config
     */
    public function __construct(
        private readonly array $config,
        private readonly SqlLexer $lexer = new SqlLexer,
    ) {}

    public function prepare(DatasetBuild $build): string
    {
        $database = $this->databaseName($build);

        if (isset($this->prepared[$database])) {
            return $database;
        }

        $owner = $this->connect($this->config['owner_username'], $this->config['owner_password']);

        try {
            // Sérialise les provisionnements concurrents du même build.
            $owner->query('SELECT GET_LOCK('.$this->literal($owner, $database).', 30)');

            $exists = $owner->query('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '.$this->literal($owner, $database))->num_rows > 0;

            if (! $exists) {
                $this->createDatabase($owner, $database, $build);
            }

            // Un rôle par base : le compte d'exécution n'a aucun droit direct, il active uniquement le
            // rôle de la base visée. Une requête ne peut donc pas lire une autre base (jeux cachés).
            $role = $this->roleAccount($owner, $database);
            $runner = $this->literal($owner, $this->config['runner_username']).'@'.$this->literal($owner, '%');
            $owner->query("CREATE ROLE IF NOT EXISTS {$role}");
            // Le nom ne contient ni « _ » ni « % » (jokers de GRANT) : le droit porte sur cette seule base.
            $owner->query('GRANT SELECT, INSERT, UPDATE, DELETE ON '.$this->quoteIdentifier($database).".* TO {$role}");
            $owner->query("GRANT {$role} TO {$runner}");

            $this->prepared[$database] = true;
        } finally {
            $owner->query('SELECT RELEASE_LOCK('.$this->literal($owner, $database).')');
            $owner->close();
        }

        return $database;
    }

    public function execute(
        DatasetBuild $build,
        GuardedQuery $query,
        int $timeoutMs,
        int $maxRows,
        array $checkQueries = [],
    ): QueryResult {
        if ($query->has(StatementKind::Routine)) {
            return QueryResult::failure('Les fonctions et procédures stockées ne sont disponibles que sur PostgreSQL.', QueryResult::ERROR_REJECTED);
        }

        foreach ($query->statements as $index => $statement) {
            if ($query->kinds[$index] === StatementKind::Ddl || $this->lexer->firstWord($statement) === 'TRUNCATE') {
                return QueryResult::failure(
                    'Les modifications de structure (CREATE, ALTER, DROP, TRUNCATE) ne sont pas encore disponibles sur MySQL : '
                        .'elles valident implicitement la transaction et ne pourraient pas être annulées. Utilisez PostgreSQL ou SQLite.',
                    QueryResult::ERROR_REJECTED,
                );
            }
        }

        $database = $this->prepare($build);
        $conn = $this->connect($this->config['runner_username'], $this->config['runner_password']);

        try {
            // Seul le rôle de cette base est actif (y compris si activate_all_roles_on_login est activé).
            $conn->query('SET ROLE '.$this->roleAccount($conn, $database));
            $conn->select_db($database);
        } catch (mysqli_sql_exception $e) {
            $conn->close();

            return QueryResult::failure("La base MySQL {$database} n'est pas accessible au compte d'exécution : relancez « php artisan sandbox:setup-mysql ».", QueryResult::ERROR_INTERNAL);
        }
        $started = hrtime(true);
        $elapsed = fn () => (int) ((hrtime(true) - $started) / 1_000_000);

        $result = ['columns' => [], 'rows' => [], 'truncated' => false, 'affected_rows' => null];
        $checks = [];

        try {
            $conn->query('SET SESSION max_execution_time = '.max(1, $timeoutMs));
            $conn->query('SET SESSION innodb_lock_wait_timeout = '.max(1, $this->config['lock_timeout_s']));
            $conn->query('SET SESSION cte_max_recursion_depth = 1000000');
            $conn->query('START TRANSACTION');

            foreach ($query->statements as $index => $statement) {
                $remaining = $timeoutMs - $elapsed();

                if ($query->kinds[$index] === StatementKind::Select && in_array($this->lexer->firstWord($statement), self::SELECT_WORDS, true)) {
                    $result = [...$result, ...$this->fetchUnbuffered($conn, $statement, $maxRows)];
                } else {
                    $result['affected_rows'] = ($result['affected_rows'] ?? 0) + $this->modifyWithWatchdog($conn, $statement, $remaining);
                }
            }

            $duration = $elapsed();

            foreach ($checkQueries as $name => $check) {
                $fetched = $this->fetchUnbuffered($conn, $check, $maxRows);
                $checks[$name] = ['columns' => $fetched['columns'], 'rows' => $fetched['rows']];
            }
        } catch (mysqli_sql_exception $e) {
            return $this->failure($e, $timeoutMs, $elapsed());
        } finally {
            try {
                $conn->query('ROLLBACK');
            } catch (mysqli_sql_exception) {
                // Connexion déjà perdue : rien à annuler.
            }
            $conn->close();
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

    public function supportsRoutines(): bool
    {
        return false; // Les routines MySQL valident implicitement la transaction.
    }

    public function supportsDdl(): bool
    {
        return false; // COMMIT implicite : impossible à annuler.
    }

    public function destroy(DatasetBuild $build): void
    {
        $database = $this->databaseName($build);
        $owner = $this->connect($this->config['owner_username'], $this->config['owner_password']);
        $owner->query('DROP DATABASE IF EXISTS '.$this->quoteIdentifier($database));
        $owner->query('DROP ROLE IF EXISTS '.$this->roleAccount($owner, $database));
        $owner->close();
        unset($this->prepared[$database]);
    }

    /**
     * Nom du rôle d'une base de build (32 caractères au plus pour MySQL).
     */
    public static function roleName(string $prefix, string $database): string
    {
        return $prefix.'r_'.substr(md5($database), 0, 16);
    }

    private function roleAccount(mysqli $conn, string $database): string
    {
        return $this->literal($conn, self::roleName($this->config['database_prefix'], $database)).'@'.$this->literal($conn, '%');
    }

    /**
     * Crée les comptes propriétaire et d'exécution. Seule opération nécessitant un
     * superutilisateur : `php artisan sandbox:setup-mysql`.
     */
    public function installRoles(string $superuser, string $superuserPassword): void
    {
        $root = $this->connect($superuser, $superuserPassword);
        $pattern = '`'.$this->namePrefix().'%`.*';

        foreach (['owner', 'runner'] as $account) {
            $user = $this->literal($root, $this->config["{$account}_username"]).'@'.$this->literal($root, '%');
            $password = $this->literal($root, $this->config["{$account}_password"]);

            $root->query("CREATE USER IF NOT EXISTS {$user} IDENTIFIED BY {$password}");
            $root->query("ALTER USER {$user} IDENTIFIED BY {$password} WITH MAX_USER_CONNECTIONS 50");
            $root->query("REVOKE ALL PRIVILEGES, GRANT OPTION FROM {$user}");
        }

        $owner = $this->literal($root, $this->config['owner_username']).'@'.$this->literal($root, '%');

        // Le propriétaire crée un rôle par base et l'accorde au compte d'exécution, qui n'a aucun droit
        // direct sur les bases de la sandbox. CREATE ROLE / DROP ROLE ne concernent que des comptes
        // verrouillés (des rôles) ; ROLE_ADMIN permet d'accorder ces rôles.
        $root->query("GRANT CREATE, DROP, ALTER, INDEX, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON {$pattern} TO {$owner} WITH GRANT OPTION");
        $root->query("GRANT CREATE ROLE, DROP ROLE ON *.* TO {$owner}");
        $root->query("GRANT ROLE_ADMIN ON *.* TO {$owner}");

        // Bases créées avant ce mécanisme (« sbx_ds1_b2_… ») : le compte d'exécution y avait accès directement.
        $legacy = '^'.preg_quote($this->config['database_prefix'], '/').'ds[0-9]+_b[0-9]+_[0-9a-f]{10}$';
        foreach ($root->query('SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME REGEXP '.$this->literal($root, $legacy))->fetch_all() as [$database]) {
            $root->query('DROP DATABASE '.$this->quoteIdentifier($database));
        }
        $root->close();
    }

    private function createDatabase(mysqli $owner, string $database, DatasetBuild $build): void
    {
        $quoted = $this->quoteIdentifier($database);

        try {
            $owner->query("CREATE DATABASE {$quoted} CHARACTER SET utf8mb4");
            $owner->select_db($database);

            foreach ($this->lexer->statements($build->validatedScript()) as $statement) {
                $owner->query($statement);
            }
        } catch (mysqli_sql_exception|SandboxUnavailable $e) {
            $owner->query("DROP DATABASE IF EXISTS {$quoted}");

            throw new SandboxUnavailable("Impossible de provisionner la base MySQL {$database} : {$e->getMessage()}", previous: $e);
        }
    }

    /**
     * @return array{columns: list<string>, rows: list<list<mixed>>, truncated: bool}
     */
    private function fetchUnbuffered(mysqli $conn, string $statement, int $maxRows): array
    {
        $result = $conn->query($statement, MYSQLI_USE_RESULT);

        if (! $result instanceof mysqli_result) {
            return ['columns' => [], 'rows' => [], 'truncated' => false];
        }

        $columns = array_map(fn ($field) => $field->name, $result->fetch_fields());
        $rows = [];
        $truncated = false;

        while (($row = $result->fetch_row()) !== null) {
            if (count($rows) >= $maxRows) {
                $truncated = true;
                // Inutile de lire le reste : on interrompt la requête côté serveur.
                $this->killQuery($conn->thread_id);
                break;
            }
            $rows[] = $row;
        }

        try {
            $result->free();
        } catch (mysqli_sql_exception) {
            // Résultat interrompu volontairement par KILL QUERY.
        }

        return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
    }

    /**
     * Envoie la modification en asynchrone et la tue si elle dépasse le temps restant.
     */
    private function modifyWithWatchdog(mysqli $conn, string $statement, int $remainingMs): int
    {
        $conn->query($statement, MYSQLI_ASYNC);
        $deadline = hrtime(true) + max(1, $remainingMs) * 1_000_000;

        while (true) {
            $read = $error = $reject = [$conn];

            if (mysqli::poll($read, $error, $reject, 0, 20_000) > 0) {
                $conn->reap_async_query();

                return max(0, $conn->affected_rows);
            }

            if (hrtime(true) >= $deadline) {
                $this->killQuery($conn->thread_id);

                try {
                    $conn->reap_async_query();
                } catch (mysqli_sql_exception) {
                    // Selon l'endroit où le KILL tombe, MySQL renvoie « interrompue » ou une erreur dérivée
                    // (ex. sous-requête interrompue → NULL → contrainte NOT NULL) : c'est un dépassement de délai.
                }

                throw new mysqli_sql_exception('Query execution was interrupted', self::ER_QUERY_INTERRUPTED);
            }
        }
    }

    private function killQuery(int $threadId): void
    {
        // Un utilisateur peut toujours interrompre ses propres connexions.
        $watchdog = $this->connect($this->config['runner_username'], $this->config['runner_password']);

        try {
            $watchdog->query('KILL QUERY '.$threadId);
        } catch (mysqli_sql_exception) {
            // Requête déjà terminée.
        } finally {
            $watchdog->close();
        }
    }

    private function failure(mysqli_sql_exception $e, int $timeoutMs, int $durationMs): QueryResult
    {
        return match ($e->getCode()) {
            self::ER_QUERY_TIMEOUT, self::ER_QUERY_INTERRUPTED => QueryResult::failure("La requête a dépassé le temps limite de {$timeoutMs} ms.", QueryResult::ERROR_TIMEOUT, $durationMs),
            self::ER_LOCK_WAIT_TIMEOUT => QueryResult::failure('Les données sont momentanément verrouillées, réessayez dans un instant.', QueryResult::ERROR_TIMEOUT, $durationMs),
            default => QueryResult::failure($e->getMessage(), QueryResult::ERROR_SQL, $durationMs),
        };
    }

    private function databaseName(DatasetBuild $build): string
    {
        $version = substr(md5($build->updated_at?->toIso8601String().$build->schema_sql), 0, 10);

        // Sans « _ » : dans un GRANT, « _ » est un joker, et un nom échappé (« \_ ») empêche MySQL
        // d'appliquer les droits hérités d'un rôle à USE base.
        return $this->namePrefix()."ds{$build->dataset_id}b{$build->id}v{$version}";
    }

    /**
     * Préfixe des bases de la sandbox, réduit aux lettres et chiffres (« sbx_ » → « sbx »).
     */
    private function namePrefix(): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $this->config['database_prefix']);
    }

    private function connect(string $username, string $password, ?string $database = null): mysqli
    {
        if (! extension_loaded('mysqli')) {
            throw new SandboxUnavailable('L\'extension PHP mysqli n\'est pas activée : décommentez « extension=mysqli » dans le php.ini (voir « php --ini »), puis relancez PHP.');
        }

        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

        try {
            $conn = mysqli_init();
            $conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 3);
            $conn->options(MYSQLI_OPT_INT_AND_FLOAT_NATIVE, true);
            $conn->real_connect($this->config['host'], $username, $password, $database, (int) $this->config['port']);
            $conn->set_charset('utf8mb4');

            return $conn;
        } catch (mysqli_sql_exception $e) {
            throw new SandboxUnavailable('Le serveur MySQL du bac à sable est injoignable.', previous: $e);
        }
    }

    private function literal(mysqli $conn, string $value): string
    {
        return "'".$conn->real_escape_string($value)."'";
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
