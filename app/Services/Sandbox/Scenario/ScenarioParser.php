<?php

namespace App\Services\Sandbox\Scenario;

use App\Services\Sandbox\Exceptions\QueryRejected;
use App\Services\Sandbox\QueryGuard;
use App\Services\Sandbox\StatementKind;

/**
 * Scénario de concurrence : des étapes attribuées à des sessions, exécutées dans l'ordre du texte.
 *
 *     -- A
 *     BEGIN ISOLATION LEVEL REPEATABLE READ;
 *     SELECT stock FROM products WHERE id = 1;
 *     -- B
 *     UPDATE products SET stock = stock - 1 WHERE id = 1;
 *
 * Une ligne « -- A » (ou « -- Session A ») ouvre une étape de la session A, jusqu'au marqueur suivant.
 */
class ScenarioParser
{
    public const SESSIONS = ['A', 'B', 'C'];

    public const MAX_STEPS = 30;

    private const MARKER = '/^[ \t]*--[ \t]*(?:session[ \t]+)?([ABC])[ \t]*(?::.*)?$/im';

    public function __construct(private readonly QueryGuard $guard) {}

    public static function isScenario(string $sql): bool
    {
        return (bool) preg_match(self::MARKER, $sql);
    }

    /**
     * @param  array{required_keywords?: list<string>, forbidden_keywords?: list<string>}  $options
     * @return list<array{session: string, sql: string, statements: list<string>}>
     *
     * @throws QueryRejected
     */
    public function parse(string $script, array $options = []): array
    {
        if (! preg_match_all(self::MARKER, $script, $markers, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            throw new QueryRejected('Un scénario commence chaque étape par une ligne « -- A » ou « -- B » indiquant la session.');
        }

        if (trim(substr($script, 0, $markers[0][0][1])) !== '' && preg_match('/\w/', preg_replace('/--[^\n]*/', '', substr($script, 0, $markers[0][0][1])))) {
            throw new QueryRejected('Le scénario doit commencer par un marqueur de session (« -- A »).');
        }

        if (count($markers) > self::MAX_STEPS) {
            throw new QueryRejected('Un scénario compte au plus '.self::MAX_STEPS.' étapes.');
        }

        $steps = [];

        foreach ($markers as $i => $marker) {
            $start = $marker[0][1] + strlen($marker[0][0]);
            $end = $markers[$i + 1][0][1] ?? strlen($script);
            $sql = trim(substr($script, $start, $end - $start));

            if ($sql === '') {
                throw new QueryRejected('L\'étape '.($i + 1).' (session '.strtoupper($marker[1][0]).') est vide.');
            }

            $query = $this->guard->inspect($sql, [
                'allowed_statements' => [StatementKind::Select->value, StatementKind::Dml->value, StatementKind::Transaction->value],
                'max_statements' => 10,
            ]);

            $steps[] = ['session' => strtoupper($marker[1][0]), 'sql' => $sql, 'statements' => $query->statements];
        }

        // Mots-clés imposés / interdits : sur le scénario entier.
        $this->checkKeywords($script, $options);

        return $steps;
    }

    /**
     * Suite des sessions (« ABAB… ») : l'entrelacement imposé par un exercice.
     *
     * @param  list<array{session: string}>  $steps
     */
    public static function interleaving(array $steps): string
    {
        return implode('', array_column($steps, 'session'));
    }

    /**
     * @param  array{required_keywords?: list<string>, forbidden_keywords?: list<string>}  $options
     */
    private function checkKeywords(string $script, array $options): void
    {
        $words = preg_split('/[^A-Z_]+/', strtoupper(preg_replace(['/--[^\n]*/', "/'(?:[^']|'')*'/"], ' ', $script)), -1, PREG_SPLIT_NO_EMPTY);

        foreach ($options['required_keywords'] ?? [] as $keyword) {
            if (! in_array(strtoupper($keyword), $words, true)) {
                throw new QueryRejected('Cet exercice impose l\'utilisation de '.strtoupper($keyword).'.');
            }
        }

        foreach ($options['forbidden_keywords'] ?? [] as $keyword) {
            if (in_array(strtoupper($keyword), $words, true)) {
                throw new QueryRejected('Cet exercice interdit l\'utilisation de '.strtoupper($keyword).'.');
            }
        }
    }
}
