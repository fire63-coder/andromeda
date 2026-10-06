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
}
