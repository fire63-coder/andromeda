<?php

namespace App\Services\Evaluation\Comparators;

use App\Services\Evaluation\Comparison;
use App\Services\Evaluation\ResultNormalizer;

/**
 * Compare deux jeux de résultats, avec ou sans prise en compte de l'ordre des lignes.
 * Par défaut les colonnes sont comparées par position (les alias sont libres) ;
 * l'option check_column_names impose aussi leurs noms.
 */
class ResultSetComparator
{
    private const MAX_DIFF_ROWS = 5;

    /**
     * @param  array{columns: list<string>, rows: list<list<mixed>>}  $actual
     * @param  array{columns: list<string>, rows: list<list<mixed>>}  $expected
     * @param  array{float_tolerance?: float, case_sensitive?: bool, check_column_names?: bool}  $options
     */
    public function compare(array $actual, array $expected, bool $ordered, array $options = []): Comparison
    {
        $normalizer = new ResultNormalizer($options);

        if ($actual['columns'] === [] && $expected['columns'] !== []) {
            return Comparison::mismatch('Votre requête ne renvoie aucun jeu de résultats.');
        }

        $actualCount = count($actual['columns']);
        $expectedCount = count($expected['columns']);

        if ($actualCount !== $expectedCount) {
            return Comparison::mismatch(
                "Votre résultat contient {$actualCount} colonne".($actualCount > 1 ? 's' : '').", {$expectedCount} ".($expectedCount > 1 ? 'sont attendues' : 'est attendue').'.',
                ['expected_columns' => $expected['columns']],
            );
        }

        if ($options['check_column_names'] ?? false) {
            $actualNames = array_map('mb_strtolower', $actual['columns']);
            $expectedNames = array_map('mb_strtolower', $expected['columns']);

            if ($actualNames !== $expectedNames) {
                return Comparison::mismatch(
                    'Les noms de colonnes ne correspondent pas à ceux attendus (pensez aux alias avec AS).',
                    ['expected_columns' => $expected['columns']],
                );
            }
        }

        $actualRows = array_map($normalizer->row(...), $actual['rows']);
        $expectedRows = array_map($normalizer->row(...), $expected['rows']);

        if (count($actualRows) !== count($expectedRows)) {
            $diff = $this->multisetDiff($actualRows, $expectedRows, $normalizer);

            return Comparison::mismatch(
                count($actualRows).' ligne'.(count($actualRows) > 1 ? 's' : '').' obtenue'.(count($actualRows) > 1 ? 's' : '').', '.count($expectedRows).' attendue'.(count($expectedRows) > 1 ? 's' : '').'.',
                $this->rowDetails($diff, $actual['rows'], $expected['rows']),
            );
        }

        $diff = $this->multisetDiff($actualRows, $expectedRows, $normalizer);

        if ($diff['missing'] !== [] || $diff['extra'] !== []) {
            return Comparison::mismatch(
                'Le nombre de lignes est correct, mais certaines valeurs ne correspondent pas.',
                $this->rowDetails($diff, $actual['rows'], $expected['rows']),
            );
        }

        if ($ordered) {
            foreach ($expectedRows as $index => $row) {
                if (! $normalizer->rowsEqual($actualRows[$index], $row)) {
                    return Comparison::mismatch(
                        'Les bonnes lignes sont là, mais pas dans le bon ordre : vérifiez votre ORDER BY.',
                        ['first_difference' => $index + 1],
                    );
                }
            }
        }

        return Comparison::match();
    }

    /**
     * Lignes attendues absentes et lignes obtenues en trop (index d'origine), en multiensemble,
     * en respectant la tolérance sur les nombres.
     *
     * @param  list<list<mixed>>  $actual
     * @param  list<list<mixed>>  $expected
     * @return array{missing: list<int>, extra: list<int>}
     */
    private function multisetDiff(array $actual, array $expected, ResultNormalizer $normalizer): array
    {
        // Lignes obtenues non encore appariées, regroupées par valeurs non numériques.
        $unmatched = [];
        foreach ($actual as $index => $row) {
            $unmatched[$normalizer->bucketKey($row)][$index] = $row;
        }

        $missing = [];
        foreach ($expected as $index => $row) {
            $key = $normalizer->bucketKey($row);
            $match = null;

            foreach ($unmatched[$key] ?? [] as $candidateIndex => $candidate) {
                if ($normalizer->rowsEqual($candidate, $row)) {
                    $match = $candidateIndex;
                    break;
                }
            }

            if ($match === null) {
                $missing[] = $index;
            } else {
                unset($unmatched[$key][$match]);
            }
        }

        $extra = [];
        foreach ($unmatched as $rows) {
            array_push($extra, ...array_keys($rows));
        }
        sort($extra);

        return ['missing' => $missing, 'extra' => $extra];
    }

    /**
     * @param  array{missing: list<int>, extra: list<int>}  $diff
     * @param  list<list<mixed>>  $actualRows
     * @param  list<list<mixed>>  $expectedRows
     * @return array{missing_rows: list<list<mixed>>, extra_rows: list<list<mixed>>, missing_count: int, extra_count: int}
     */
    private function rowDetails(array $diff, array $actualRows, array $expectedRows): array
    {
        return [
            'missing_rows' => array_map(fn (int $i) => $expectedRows[$i], array_slice($diff['missing'], 0, self::MAX_DIFF_ROWS)),
            'extra_rows' => array_map(fn (int $i) => $actualRows[$i], array_slice($diff['extra'], 0, self::MAX_DIFF_ROWS)),
            'missing_count' => count($diff['missing']),
            'extra_count' => count($diff['extra']),
        ];
    }
}
