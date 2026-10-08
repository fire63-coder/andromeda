<?php

namespace App\Services\Sandbox\Scenario;

use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use App\Services\Sandbox\QueryResult;
use PDO;
use PgSql\Connection;
use Throwable;

/**
 * Exécute un scénario de concurrence : chaque session est une vraie connexion PostgreSQL
 * (compte d'exécution), pilotée de façon asynchrone. Une instruction qui ne répond pas
 * dans le délai d'observation est « bloquée » (verrou) : les étapes suivantes de sa session
 * attendent, celles des autres sessions continuent, comme pour de vrais clients.
 *
 * Les sessions travaillent sur une copie jetable des tables (schéma « sc_… » créé et supprimé
 * par le compte propriétaire) : leurs COMMIT sont réels mais ne touchent jamais le jeu de données.
 */
class PostgresScenarioRunner
{
    /** Au-delà, une instruction est considérée comme bloquée. */
    private const BLOCK_DETECTION_MS = 300;

    private const MAX_ROWS = 50;

    /** @var array<string, Connection> */
    private array $sessions = [];

    /** @var array<string, list<array{step: int, sql: string}>> instructions en attente, par session */
    private array $queues = [];

    /** @var array<string, array{step: int, sql: string}|null> instruction en cours, par session */
    private array $running = [];

    /** @var list<array<string, mixed>> */
    private array $timeline = [];

    private int $currentStep = 0;

    /**
     * @param  array<string, mixed>  $config  sandbox.drivers.pgsql
     * @param  \Closure(string, string): PDO  $connect  (utilisateur, mot de passe) => PDO
     */
    public function __construct(
        private readonly array $config,
        private readonly \Closure $connect,
    ) {}

    /**
     * @param  list<array{session: string, sql: string, statements: list<string>}>  $steps
     * @param  array<string, string>  $checkQueries  état final, lu après la fin de toutes les sessions
     */
    public function run(string $buildSchema, array $steps, array $checkQueries, int $statementTimeoutMs): QueryResult
    {
        if (! function_exists('pg_send_query')) {
            return QueryResult::failure('L\'extension PHP pgsql (pg_connect) est nécessaire aux scénarios de concurrence : activez « extension=pgsql » dans le php.ini.', QueryResult::ERROR_INTERNAL);
        }

        $started = hrtime(true);
        $owner = ($this->connect)($this->config['owner_username'], $this->config['owner_password']);
        $scratch = ($this->config['schema_prefix'] ?? '').'sc_'.time().'_'.bin2hex(random_bytes(4));

        try {
            $this->createScratchCopy($owner, $buildSchema, $scratch);

            foreach (array_unique(array_column($steps, 'session')) as $session) {
                $this->sessions[$session] = $this->openSession($scratch, $statementTimeoutMs);
                $this->queues[$session] = [];
                $this->running[$session] = null;
            }

            foreach ($steps as $index => $step) {
                $this->currentStep = $index;
                $this->timeline[$index] = [
                    'step' => $index + 1, 'session' => $step['session'], 'sql' => $step['sql'],
                    'status' => 'pending', 'waited' => false, 'queued' => false, 'completed_at_step' => null,
                    'columns' => [], 'rows' => [], 'affected_rows' => null, 'error' => null, 'sqlstate' => null,
                ];

                $this->pump();

                foreach ($step['statements'] as $sql) {
                    $this->queues[$step['session']][] = ['step' => $index, 'sql' => $sql];
                }

                // Session libre : ses instructions partent maintenant ; sinon elles attendent leur tour.
                if ($this->running[$step['session']] === null) {
                    $this->advance($step['session']);
                } else {
                    // La session est encore bloquée sur une étape précédente : celle-ci attend son tour.
                    $this->timeline[$index]['queued'] = true;
                }
            }

            $this->currentStep = count($steps);
            $this->drain($statementTimeoutMs + (int) $this->config['lock_timeout_ms'] + 2000);
            $this->closeSessions();

            $checks = $this->readFinalState($scratch, $checkQueries);
        } catch (SandboxUnavailable $e) {
            return QueryResult::failure($e->getMessage(), QueryResult::ERROR_INTERNAL);
        } finally {
            $this->closeSessions();
            $this->dropScratchCopy($owner, $scratch);
        }

        return new QueryResult(
            success: true,
            durationMs: (int) ((hrtime(true) - $started) / 1_000_000),
            checks: $checks,
            timeline: array_values($this->timeline),
        );
    }

    /**
     * Récupère les instructions terminées et relance les sessions débloquées.
     */
    private function pump(): void
    {
        do {
            $progress = false;

            foreach (array_keys($this->sessions) as $session) {
                if ($this->running[$session] !== null && ! pg_connection_busy($this->sessions[$session])) {
                    $this->collect($session);
                    $this->advance($session);
                    $progress = true;
                }
            }
        } while ($progress);
    }

    /**
     * Envoie les instructions en attente de la session, une à une, tant qu'elles se terminent vite.
     */
    private function advance(string $session): void
    {
        while ($this->running[$session] === null && $this->queues[$session] !== []) {
            $item = array_shift($this->queues[$session]);
            $this->running[$session] = $item;

            if ($this->timeline[$item['step']]['status'] === 'pending') {
                $this->timeline[$item['step']]['status'] = 'running';
            }

            pg_send_query($this->sessions[$session], $item['sql']);

            if (! $this->waitFor($session, self::BLOCK_DETECTION_MS)) {
                $this->timeline[$item['step']]['waited'] = true;

                return; // bloquée : on laisse les autres sessions avancer
            }

            $this->collect($session);
        }
    }

    private function waitFor(string $session, int $ms): bool
    {
        $deadline = hrtime(true) + $ms * 1_000_000;

        while (pg_connection_busy($this->sessions[$session])) {
            if (hrtime(true) > $deadline) {
                return false;
            }
            usleep(5_000);
        }

        return true;
    }

    private function collect(string $session): void
    {
        $item = $this->running[$session];
        $this->running[$session] = null;
        $entry = &$this->timeline[$item['step']];

        while (($result = pg_get_result($this->sessions[$session])) !== false) {
            $status = pg_result_status($result);

            if ($status === PGSQL_FATAL_ERROR || $status === PGSQL_BAD_RESPONSE || $status === PGSQL_NONFATAL_ERROR) {
                $entry['error'] = $this->cleanError((string) pg_result_error($result));
                $entry['sqlstate'] = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE) ?: null;
            } elseif ($status === PGSQL_TUPLES_OK) {
                $fields = pg_num_fields($result);
                $entry['columns'] = $fields > 0 ? array_map(fn (int $i) => pg_field_name($result, $i), range(0, $fields - 1)) : [];
                $entry['rows'] = array_slice(pg_fetch_all($result, PGSQL_NUM) ?: [], 0, self::MAX_ROWS);
            } elseif ($status === PGSQL_COMMAND_OK) {
                $entry['affected_rows'] = ($entry['affected_rows'] ?? 0) + pg_affected_rows($result);
            }

            pg_free_result($result);
        }

        $entry['status'] = $entry['error'] !== null ? 'error' : 'done';

        if ($entry['waited'] || $entry['queued']) {
            // Débloquée par l'étape exécutée juste avant (numérotation à partir de 1).
            $entry['completed_at_step'] = max(1, min($this->currentStep, count($this->timeline)));
        }

        // Après une erreur, le reste de l'étape n'est pas envoyé (comme un client qui s'arrête).
        if ($entry['error'] !== null) {
            $this->queues[$session] = array_values(array_filter($this->queues[$session], fn ($queued) => $queued['step'] !== $item['step']));
        }
    }

    /**
     * Fin du scénario : laisse les sessions bloquées se terminer (le verrou peut se libérer),
     * dans la limite du délai ; au-delà, la requête est annulée.
     */
    private function drain(int $budgetMs): void
    {
        $deadline = hrtime(true) + $budgetMs * 1_000_000;

        while (hrtime(true) < $deadline) {
            $this->pump();

            if (array_filter($this->running) === []) {
                return;
            }

            usleep(10_000);
        }

        foreach ($this->running as $session => $item) {
            if ($item !== null) {
                pg_cancel_query($this->sessions[$session]);
                $this->timeline[$item['step']]['status'] = 'error';
                $this->timeline[$item['step']]['error'] = 'Toujours bloquée à la fin du scénario : la requête a été annulée.';
                $this->running[$session] = null;
            }
        }
    }

    private function openSession(string $scratch, int $statementTimeoutMs): Connection
    {
        $connection = @pg_connect(sprintf(
            "host=%s port=%s dbname=%s user=%s password='%s' connect_timeout=3 application_name=andromeda_scenario",
            $this->config['host'], $this->config['port'], $this->config['database'],
            $this->config['runner_username'], addcslashes((string) $this->config['runner_password'], "'\\"),
        ), PGSQL_CONNECT_FORCE_NEW);

        if (! $connection) {
            throw new SandboxUnavailable('Le serveur PostgreSQL du bac à sable est injoignable.');
        }

        // Paramètres de session fixés par le pilote ; l'élève ne peut pas émettre de SET (QueryGuard).
        pg_query($connection, 'SET statement_timeout = '.max(1, $statementTimeoutMs));
        pg_query($connection, 'SET lock_timeout = '.max(1000, (int) $this->config['lock_timeout_ms'] * 4));
        pg_query($connection, 'SET idle_in_transaction_session_timeout = 30000');
        pg_query($connection, 'SET search_path TO '.pg_escape_identifier($connection, $scratch));

        return $connection;
    }

    private function closeSessions(): void
    {
        foreach ($this->sessions as $connection) {
            try {
                if (pg_connection_busy($connection)) {
                    pg_cancel_query($connection);
                }
                pg_close($connection);
            } catch (Throwable) {
                // Déjà fermée.
            }
        }

        $this->sessions = [];
    }

    private function createScratchCopy(PDO $owner, string $buildSchema, string $scratch): void
    {
        $quotedBuild = $this->quote($buildSchema);
        $quotedScratch = $this->quote($scratch);
        $runner = $this->quote($this->config['runner_username']);

        $owner->exec("CREATE SCHEMA {$quotedScratch}");

        $tables = $owner->query('SELECT c.oid, c.relname FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace '
            .'WHERE n.nspname = '.$owner->quote($buildSchema)." AND c.relkind = 'r' ORDER BY c.relname")->fetchAll(PDO::FETCH_NUM);

        foreach ($tables as [$oid, $table]) {
            $quotedTable = $this->quote($table);
            $columns = implode(', ', array_map(fn ($c) => $this->quote($c), $owner->query(
                'SELECT attname FROM pg_attribute WHERE attrelid = '.(int) $oid." AND attnum > 0 AND NOT attisdropped AND attgenerated = '' ORDER BY attnum",
            )->fetchAll(PDO::FETCH_COLUMN)));

            $owner->exec("CREATE TABLE {$quotedScratch}.{$quotedTable} (LIKE {$quotedBuild}.{$quotedTable} INCLUDING ALL)");
            $owner->exec("INSERT INTO {$quotedScratch}.{$quotedTable} ({$columns}) OVERRIDING SYSTEM VALUE SELECT {$columns} FROM {$quotedBuild}.{$quotedTable}");
        }

        $owner->exec("GRANT USAGE ON SCHEMA {$quotedScratch} TO {$runner}");
        $owner->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA {$quotedScratch} TO {$runner}");
        $owner->exec("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA {$quotedScratch} TO {$runner}");
    }

    /**
     * @param  array<string, string>  $checkQueries
     * @return array<string, array{columns: list<string>, rows: list<list<mixed>>}>
     */
    private function readFinalState(string $scratch, array $checkQueries): array
    {
        if ($checkQueries === []) {
            return [];
        }

        $pdo = ($this->connect)($this->config['runner_username'], $this->config['runner_password']);
        $pdo->exec('SET search_path TO '.$this->quote($scratch));
        $checks = [];

        foreach ($checkQueries as $name => $sql) {
            $statement = $pdo->query($sql);
            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $columns[] = $statement->getColumnMeta($i)['name'] ?? "col{$i}";
            }
            $checks[$name] = ['columns' => $columns, 'rows' => array_slice($statement->fetchAll(PDO::FETCH_NUM), 0, 500)];
        }

        return $checks;
    }

    private function dropScratchCopy(PDO $owner, string $scratch): void
    {
        try {
            $owner->exec('SET lock_timeout = 5000');
            $owner->exec('DROP SCHEMA IF EXISTS '.$this->quote($scratch).' CASCADE');
        } catch (Throwable $e) {
            // Un verrou résiduel : `sandbox:purge-scenarios` le supprimera plus tard.
            report($e);
        }
    }

    private function cleanError(string $message): string
    {
        $lines = array_filter(explode("\n", preg_replace('/^ERROR:\s+/', '', trim($message))), fn ($line) => ! str_starts_with($line, 'LINE ') && trim($line, " ^\t") !== '');

        return preg_replace('/^(HINT|DETAIL|CONTEXT):\s+/m', '$1 : ', trim(implode("\n", $lines)));
    }

    private function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
