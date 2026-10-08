<?php

namespace Tests\Unit\Sandbox;

use App\Services\Sandbox\Exceptions\QueryRejected;
use App\Services\Sandbox\QueryGuard;
use App\Services\Sandbox\Scenario\ScenarioParser;
use App\Services\Sandbox\SqlLexer;
use App\Services\Sandbox\StatementKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScenarioParserTest extends TestCase
{
    private ScenarioParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new ScenarioParser(new QueryGuard(new SqlLexer));
    }

    #[Test]
    public function steps_are_split_on_session_markers(): void
    {
        $steps = $this->parser->parse("-- A\nBEGIN ISOLATION LEVEL REPEATABLE READ;\nSELECT 1;\n-- Session B : livraison\nUPDATE products SET stock = stock + 1;\n-- A\nCOMMIT;");

        $this->assertSame('ABA', ScenarioParser::interleaving($steps));
        $this->assertSame(['BEGIN ISOLATION LEVEL REPEATABLE READ', 'SELECT 1'], $steps[0]['statements']);
        $this->assertTrue(ScenarioParser::isScenario("-- B\nSELECT 1"));
        $this->assertFalse(ScenarioParser::isScenario("SELECT 1 -- A\n"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidScenarios(): array
    {
        return [
            'sans marqueur' => ['SELECT 1'],
            'instruction avant le premier marqueur' => ["SELECT 1;\n-- A\nSELECT 2"],
            'étape vide' => ["-- A\n-- B\nSELECT 1"],
            'SET de session' => ["-- A\nSET statement_timeout = 0"],
            'SET LOCAL' => ["-- A\nBEGIN;\nSET LOCAL lock_timeout = 0"],
            'DDL' => ["-- A\nDROP TABLE products"],
            'mot interdit' => ["-- A\nSELECT pg_terminate_backend(1)"],
            'trop d\'étapes' => [str_repeat("-- A\nSELECT 1;\n", 31)],
        ];
    }

    #[Test]
    #[DataProvider('invalidScenarios')]
    public function invalid_scenarios_are_rejected(string $script): void
    {
        $this->expectException(QueryRejected::class);

        $this->parser->parse($script);
    }

    #[Test]
    public function transaction_control_is_only_allowed_in_scenarios(): void
    {
        $guard = new QueryGuard(new SqlLexer);
        $allowed = ['allowed_statements' => ['select', 'dml', 'transaction'], 'max_statements' => 10];

        $query = $guard->inspect('START TRANSACTION ISOLATION LEVEL SERIALIZABLE; SET TRANSACTION ISOLATION LEVEL READ COMMITTED; SAVEPOINT s1; ROLLBACK TO SAVEPOINT s1; LOCK TABLE products IN SHARE MODE NOWAIT; COMMIT', $allowed);
        $this->assertSame(array_fill(0, 6, StatementKind::Transaction), $query->kinds);

        // Hors scénario, le contrôle de transaction reste interdit…
        try {
            $guard->inspect('COMMIT', ['allowed_statements' => ['select', 'dml'], 'max_statements' => 5]);
            $this->fail('COMMIT accepté hors scénario.');
        } catch (QueryRejected) {
        }

        // … et un script de jeu de données inspecté ensuite ne profite pas de l'autorisation précédente.
        $guard->inspect('BEGIN', $allowed);
        $this->expectException(QueryRejected::class);
        $guard->inspectScript('CREATE TABLE t (x INT); COMMIT;');
    }
}
