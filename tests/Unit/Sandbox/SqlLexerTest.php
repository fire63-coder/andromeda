<?php

namespace Tests\Unit\Sandbox;

use App\Services\Sandbox\SqlLexer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class SqlLexerTest extends TestCase
{
    private SqlLexer $lexer;

    protected function setUp(): void
    {
        $this->lexer = new SqlLexer;
    }

    #[Test]
    public function it_splits_statements_on_top_level_semicolons(): void
    {
        $this->assertSame(
            ['SELECT 1', 'SELECT 2'],
            $this->lexer->statements("SELECT 1;\n SELECT 2;"),
        );
    }

    #[Test]
    public function it_ignores_semicolons_inside_strings_identifiers_and_comments(): void
    {
        $statements = $this->lexer->statements(<<<'SQL'
            SELECT 'a;b', "c;d", `e;f`, [g;h] -- commentaire ;
            FROM t /* ; */ WHERE x = 'l''apostrophe;';
            SELECT 2
            SQL);

        $this->assertCount(2, $statements);
        $this->assertStringEndsWith("'l''apostrophe;'", $statements[0]);
    }

    #[Test]
    public function it_keeps_postgres_dollar_quoted_bodies_whole(): void
    {
        $statements = $this->lexer->statements('CREATE FUNCTION f() RETURNS int AS $body$ BEGIN RETURN 1; END; $body$ LANGUAGE plpgsql; SELECT f()');

        $this->assertCount(2, $statements);
    }

    #[Test]
    public function it_keeps_trigger_bodies_whole(): void
    {
        $statements = $this->lexer->statements(<<<'SQL'
            CREATE TRIGGER t AFTER INSERT ON orders BEGIN
                UPDATE stock SET qty = CASE WHEN qty > 0 THEN qty - 1 ELSE 0 END;
                INSERT INTO log VALUES (1);
            END;
            SELECT 1;
            SQL);

        $this->assertCount(2, $statements);
        $this->assertStringEndsWith('END', $statements[0]);
    }

    #[Test]
    public function it_drops_empty_and_comment_only_statements(): void
    {
        $this->assertSame(['SELECT 1'], $this->lexer->statements(";; -- rien\n SELECT 1 ; /* fin */"));
    }

    #[Test]
    public function words_exclude_strings_and_comments(): void
    {
        $this->assertSame(
            ['SELECT', 'NAME', 'FROM', 'T'],
            $this->lexer->words("SELECT name /* DROP */ FROM t -- ATTACH\n"),
        );
        $this->assertSame(['SELECT'], $this->lexer->words("SELECT 'PRAGMA'"));
    }
}
