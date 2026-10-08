<?php

namespace Tests\Unit\Sandbox;

use App\Services\Sandbox\Exceptions\QueryRejected;
use App\Services\Sandbox\QueryGuard;
use App\Services\Sandbox\SqlLexer;
use App\Services\Sandbox\StatementKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QueryGuardTest extends TestCase
{
    private QueryGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new QueryGuard(new SqlLexer);
    }

    #[Test]
    public function it_accepts_a_single_select_by_default(): void
    {
        $query = $this->guard->inspect('WITH t AS (SELECT 1) SELECT * FROM t;');

        $this->assertSame([StatementKind::Select], $query->kinds);
        $this->assertTrue($query->isReadOnly());
    }

    #[Test]
    public function it_rejects_modifications_when_only_select_is_allowed(): void
    {
        $this->expectException(QueryRejected::class);

        $this->guard->inspect('DELETE FROM customers');
    }

    #[Test]
    public function it_rejects_extra_statements_for_select_exercises(): void
    {
        $this->expectExceptionMessage('Une seule instruction');

        $this->guard->inspect('SELECT 1; SELECT 2');
    }

    #[Test]
    public function it_accepts_dml_when_allowed(): void
    {
        $query = $this->guard->inspect(
            "UPDATE products SET price = 1; DELETE FROM orders WHERE status = 'annulée';",
            ['allowed_statements' => ['dml']],
        );

        $this->assertSame([StatementKind::Dml, StatementKind::Dml], $query->kinds);
        $this->assertFalse($query->isReadOnly());
    }

    #[Test]
    public function a_data_modifying_cte_counts_as_dml(): void
    {
        $this->expectException(QueryRejected::class);

        $this->guard->inspect('WITH d AS (DELETE FROM orders RETURNING *) SELECT * FROM d');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function dangerousQueries(): array
    {
        return [
            'attach' => ["ATTACH DATABASE '/etc/passwd' AS x"],
            'pragma' => ['PRAGMA writable_schema = 1'],
            'transaction' => ['COMMIT'],
            'begin' => ['BEGIN'],
            'set' => ['SET statement_timeout = 0'],
            'set_config' => ["SELECT set_config('statement_timeout', '0', true)"],
            'copy' => ["COPY customers TO '/tmp/x'"],
            'read file' => ["SELECT pg_read_file('/etc/passwd')"],
            'dynamic sql' => ["SELECT query_to_xml('select 1', true, true, '')"],
            'anonymous block' => ['DO $$ BEGIN PERFORM 1; END $$'],
            'create role' => ['CREATE ROLE hacker'],
            'create function' => ['CREATE FUNCTION f() RETURNS int AS $$ SELECT 1 $$ LANGUAGE sql'],
            'load extension' => ["SELECT load_extension('/tmp/x.so')"],
            'vacuum into' => ["VACUUM INTO '/tmp/copy.sqlite'"],
        ];
    }

    #[Test]
    #[DataProvider('dangerousQueries')]
    public function it_always_rejects_dangerous_statements(string $sql): void
    {
        $this->expectException(QueryRejected::class);

        $this->guard->inspect($sql, ['allowed_statements' => ['select', 'dml', 'ddl']]);
    }

    #[Test]
    public function keywords_inside_strings_are_not_flagged(): void
    {
        $query = $this->guard->inspect("SELECT 'ATTACH; DROP TABLE x; PRAGMA' AS texte");

        $this->assertCount(1, $query->statements);
    }

    #[Test]
    public function it_enforces_required_and_forbidden_keywords(): void
    {
        $this->guard->inspect('SELECT * FROM a JOIN b ON a.id = b.a_id', ['required_keywords' => ['join']]);

        $this->expectExceptionMessage("impose l'utilisation de JOIN");
        $this->guard->inspect('SELECT * FROM a, b WHERE a.id = b.a_id', ['required_keywords' => ['join']]);
    }

    #[Test]
    public function it_rejects_unknown_statements_with_a_helpful_message(): void
    {
        $this->expectExceptionMessage('« SELEC »');

        $this->guard->inspect('SELEC * FROM customers');
    }

    #[Test]
    public function it_rejects_empty_and_oversized_queries(): void
    {
        config(['sandbox.max_query_length' => 20]);

        $this->expectExceptionMessage('trop longue');
        $this->guard->inspect('SELECT * FROM customers WHERE 1 = 1');
    }

    #[Test]
    public function dataset_scripts_accept_schema_and_data_but_not_escapes(): void
    {
        $this->guard->inspectScript("CREATE TABLE t (id INTEGER PRIMARY KEY, label TEXT);\nINSERT INTO t VALUES (1, 'a;b');");

        $this->expectException(QueryRejected::class);
        $this->guard->inspectScript("CREATE TABLE t (x INT); ATTACH DATABASE '/var/www/public/x.php' AS evil;");
    }

    private const ROUTINES = ['allowed_statements' => ['routine', 'select', 'ddl']];

    #[Test]
    public function it_classifies_functions_procedures_and_calls_as_routines(): void
    {
        $query = $this->guard->inspect(<<<'SQL'
            CREATE OR REPLACE FUNCTION ttc(p numeric) RETURNS numeric LANGUAGE plpgsql AS $$
            DECLARE
                taux numeric := 1.2;
            BEGIN
                UPDATE products SET price = price WHERE id = 0;
                IF p IS NULL THEN
                    RETURN NULL;
                END IF;
                RETURN ROUND(p * taux, 2);
            END
            $$;
            CREATE TRIGGER t AFTER UPDATE OF price ON products FOR EACH ROW EXECUTE FUNCTION ttc();
            CALL augmenter(2, 5);
            DROP FUNCTION ttc(numeric);
            SQL, self::ROUTINES);

        $this->assertSame([StatementKind::Routine, StatementKind::Ddl, StatementKind::Routine, StatementKind::Routine], $query->kinds);
    }

    #[Test]
    public function routines_need_to_be_allowed_by_the_exercise(): void
    {
        $this->expectExceptionMessage("n'accepte que les modifications de structure");

        $this->guard->inspect('CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$', ['allowed_statements' => ['ddl']]);
    }

    #[Test]
    public function required_keywords_are_searched_in_routine_bodies(): void
    {
        $sql = 'CREATE FUNCTION f(x int) RETURNS int LANGUAGE plpgsql AS $$ BEGIN IF x > 0 THEN RETURN 1; END IF; RETURN 0; END $$';

        $this->guard->inspect($sql, [...self::ROUTINES, 'required_keywords' => ['IF']]);

        $this->expectExceptionMessage('impose');
        $this->guard->inspect($sql, [...self::ROUTINES, 'required_keywords' => ['LOOP']]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function dangerousRoutines(): array
    {
        $body = fn (string $code) => "CREATE FUNCTION f() RETURNS int LANGUAGE plpgsql AS \$\$ BEGIN {$code} RETURN 1; END \$\$";

        return [
            'SET dans le corps' => [$body('SET statement_timeout = 0;')],
            'SET LOCAL après THEN' => [$body('IF true THEN SET LOCAL statement_timeout = 0; END IF;')],
            'RESET' => [$body('RESET ALL;')],
            'COMMIT' => [$body('COMMIT;')],
            'SQL dynamique' => [$body("EXECUTE 'SELECT 1';")],
            'RETURN QUERY EXECUTE' => ["CREATE FUNCTION f() RETURNS SETOF int LANGUAGE plpgsql AS \$\$ BEGIN RETURN QUERY EXECUTE 'SELECT 1'; END \$\$"],
            'interception du timeout' => [$body('BEGIN PERFORM 1; EXCEPTION WHEN query_canceled THEN NULL; END;')],
            'interception par SQLSTATE' => [$body("BEGIN PERFORM 1; EXCEPTION WHEN SQLSTATE '57014' THEN NULL; END;")],
            'mot interdit dans le corps' => [$body('PERFORM pg_terminate_backend(1);')],
            'identifiant délimité' => [$body('PERFORM "pg_terminate_backend"(1);')],
            'indice de tableau' => [$body('PERFORM (ARRAY[1])[pg_terminate_backend(1)::int];')],
            'fonction imbriquée' => [$body('CREATE FUNCTION g() RETURNS int LANGUAGE sql AS $g$ SELECT 1 $g$;')],
            'clause SET' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql SET statement_timeout = 0 AS $$ SELECT 1 $$'],
            'langage non fiable' => ['CREATE FUNCTION f() RETURNS int LANGUAGE plpython3u AS $$ return 1 $$'],
            'langage entre apostrophes' => ["CREATE FUNCTION f() RETURNS int LANGUAGE 'plpgsql' AS \$\$ BEGIN RETURN 1; END \$\$"],
            'corps entre apostrophes' => ["CREATE FUNCTION f() RETURNS int LANGUAGE sql AS 'SELECT pg_terminate_backend(1)'"],
            'BEGIN ATOMIC' => ['CREATE FUNCTION f() RETURNS int LANGUAGE sql BEGIN ATOMIC SELECT 1; END'],
            'ALTER FUNCTION' => ['ALTER FUNCTION f() SET statement_timeout = 0'],
            'bloc anonyme' => ['DO $$ BEGIN PERFORM 1; END $$'],
            'identifiant délimité hors fonction' => ['SELECT "pg_terminate_backend"(1)'],
        ];
    }

    #[Test]
    #[DataProvider('dangerousRoutines')]
    public function it_rejects_dangerous_routines(string $sql): void
    {
        $this->expectException(QueryRejected::class);

        $this->guard->inspect($sql, self::ROUTINES);
    }

    #[Test]
    public function dataset_scripts_cannot_define_routines(): void
    {
        $this->expectExceptionMessage('jeu de données');

        $this->guard->inspectScript('CREATE TABLE t (x INT); CREATE FUNCTION f() RETURNS int LANGUAGE sql AS $$ SELECT 1 $$;');
    }

    #[Test]
    public function explain_analyze_of_a_modification_is_a_modification(): void
    {
        $this->assertSame([StatementKind::Select], $this->guard->inspect('EXPLAIN SELECT * FROM t')->kinds);

        $this->expectExceptionMessage("n'accepte que");
        $this->guard->inspect('EXPLAIN ANALYZE DELETE FROM t');
    }

    /**
     * Requêtes que le lexer et le moteur liraient différemment (contournements confirmés en revue).
     *
     * @return array<string, array{0: string}>
     */
    public static function ambiguousQueries(): array
    {
        return [
            'antislash MySQL' => ["SELECT 'a\\'' , SLEEP(2) , ''"],
            'guillemets MySQL' => ['SELECT "a\\"" , SLEEP(2) , ""'],
            'chaîne E PostgreSQL' => ["SELECT E'a\\'' , pg_sleep_for('2 s') , ''"],
            '-- sans espace' => ["SELECT 1 --SLEEP(2)\n"],
            'dièse MySQL' => ["SELECT 1 # '\n, SLEEP(2) -- '"],
            'commentaire exécutable' => ['SELECT 1 /*! , SLEEP(2) */'],
            'indication optimiseur' => ['SELECT /*+ MAX_EXECUTION_TIME(0) */ 1'],
            'commentaire imbriqué' => ["SELECT 1 /* /* */ ' */ , pg_sleep_for('2 s') --'"],
            'commentaire non fermé' => ['SELECT 1 /* fin'],
            'chaîne non fermée' => ["SELECT 'abc"],
            'dollar hors fonction' => ['SELECT 1 AS $$, SLEEP(2) AS $$'],
            'échappements Unicode' => ['SELECT U&"pg\\005fsleep"(1)'],
            'pg_sleep' => ['SELECT pg_sleep(10)'],
            'requêtes des autres (MySQL)' => ['SELECT INFO FROM information_schema.PROCESSLIST'],
            'requêtes des autres (PostgreSQL)' => ['SELECT query FROM "pg_stat_activity"'],
        ];
    }

    #[Test]
    #[DataProvider('ambiguousQueries')]
    public function ambiguous_or_spying_queries_are_rejected(string $sql): void
    {
        $this->expectException(QueryRejected::class);

        $this->guard->inspect($sql);
    }

    #[Test]
    public function ordinary_strings_comments_and_backslashes_are_still_accepted(): void
    {
        foreach ([
            "SELECT 'l''apostrophe' -- commentaire\nFROM t",
            "SELECT name FROM t WHERE name LIKE '%\\_%' /* bloc */",
            "SELECT 'C:\\\\dossier\\\\' AS chemin",
            'SELECT "colonne" FROM t',
        ] as $sql) {
            $this->assertSame([StatementKind::Select], $this->guard->inspect($sql)->kinds, $sql);
        }
    }

    #[Test]
    public function graded_exercises_cannot_replace_dataset_tables(): void
    {
        $options = ['allowed_statements' => ['ddl', 'dml'], 'max_statements' => 5, 'protected_tables' => ['salary_audit', 'employees']];

        foreach ([
            'CREATE TEMP TABLE salary_audit AS SELECT 1',
            'CREATE TEMPORARY VIEW x AS SELECT 1',
            'DROP TABLE IF EXISTS salary_audit',
            'DROP TABLE "employees"',
            'ALTER TABLE employees RENAME TO e2',
        ] as $sql) {
            try {
                $this->guard->inspect($sql, $options);
                $this->fail("Accepté : {$sql}");
            } catch (QueryRejected) {
                $this->addToAssertionCount(1);
            }
        }

        // Le DDL ordinaire reste permis.
        $this->guard->inspect('CREATE INDEX ix ON employees (manager_id); ALTER TABLE employees ADD COLUMN prime numeric; CREATE TABLE notes (x int)', $options);
    }

    #[Test]
    public function dataset_scripts_only_reject_backslash_escapes_for_mysql(): void
    {
        $script = "CREATE TABLE t (x TEXT); INSERT INTO t VALUES ('a\\''b')";

        $this->guard->inspectScript($script, 'pgsql');
        $this->expectException(QueryRejected::class);
        $this->guard->inspectScript($script, 'mysql');
    }
}
