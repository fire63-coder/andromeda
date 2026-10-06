<?php

namespace Tests\Unit\Evaluation;

use App\Services\Evaluation\Comparators\ResultSetComparator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ResultSetComparatorTest extends TestCase
{
    private ResultSetComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new ResultSetComparator;
    }

    /**
     * @param  list<list<mixed>>  $rows
     * @param  list<string>|null  $columns
     * @return array{columns: list<string>, rows: list<list<mixed>>}
     */
    private function set(array $rows, ?array $columns = null): array
    {
        return ['columns' => $columns ?? ['a', 'b'], 'rows' => $rows];
    }

    #[Test]
    public function unordered_comparison_ignores_row_order(): void
    {
        $result = $this->comparator->compare($this->set([[2, 'y'], [1, 'x']]), $this->set([[1, 'x'], [2, 'y']]), ordered: false);

        $this->assertTrue($result->matches);
    }

    #[Test]
    public function ordered_comparison_reports_the_first_misplaced_row(): void
    {
        $result = $this->comparator->compare($this->set([[2, 'y'], [1, 'x']]), $this->set([[1, 'x'], [2, 'y']]), ordered: true);

        $this->assertFalse($result->matches);
        $this->assertStringContainsString('ORDER BY', $result->message);
        $this->assertSame(1, $result->details['first_difference']);
    }

    #[Test]
    public function engines_numeric_representations_are_equivalent(): void
    {
        // PostgreSQL : NUMERIC en chaîne ; SQLite : flottant ; MySQL : parfois entier.
        $result = $this->comparator->compare($this->set([['24.90', 3]]), $this->set([[24.9, '3']]), ordered: true);

        $this->assertTrue($result->matches);
    }

    #[Test]
    public function float_tolerance_is_configurable(): void
    {
        $actual = $this->set([[27.390000000000004, 'x']]);

        $this->assertTrue($this->comparator->compare($actual, $this->set([[27.39, 'x']]), true)->matches);
        $this->assertFalse($this->comparator->compare($this->set([[27.4, 'x']]), $this->set([[27.39, 'x']]), true)->matches);
        $this->assertTrue($this->comparator->compare($this->set([[27.4, 'x']]), $this->set([[27.39, 'x']]), true, ['float_tolerance' => 0.01])->matches);
    }

    #[Test]
    public function tolerance_is_absolute_even_for_large_values(): void
    {
        // 273,90 € attendus : 271,00 € ne doit pas passer avec une tolérance au centime.
        $result = $this->comparator->compare($this->set([[271.0, 'x']]), $this->set([[273.9, 'x']]), false, ['float_tolerance' => 0.01]);

        $this->assertFalse($result->matches);
    }

    #[Test]
    public function column_aliases_are_free_unless_names_are_checked(): void
    {
        $actual = $this->set([[1, 'x']], ['id', 'libelle']);

        $this->assertTrue($this->comparator->compare($actual, $this->set([[1, 'x']]), true)->matches);
        $this->assertFalse($this->comparator->compare($actual, $this->set([[1, 'x']]), true, ['check_column_names' => true])->matches);
    }

    #[Test]
    public function it_explains_column_count_mismatches(): void
    {
        $result = $this->comparator->compare($this->set([[1]], ['a']), $this->set([[1, 'x']]), false);

        $this->assertSame('Votre résultat contient 1 colonne, 2 sont attendues.', $result->message);
        $this->assertSame(['a', 'b'], $result->details['expected_columns']);
    }

    #[Test]
    public function it_lists_missing_and_extra_rows_as_a_multiset(): void
    {
        $result = $this->comparator->compare(
            $this->set([[1, 'x'], [1, 'x'], [3, 'z']]),
            $this->set([[1, 'x'], [2, 'y'], [3, 'z']]),
            ordered: false,
        );

        $this->assertFalse($result->matches);
        $this->assertSame([[2, 'y']], $result->details['missing_rows']);
        $this->assertSame([[1, 'x']], $result->details['extra_rows']);
    }

    #[Test]
    public function null_is_distinct_from_empty_string_and_zero(): void
    {
        $this->assertFalse($this->comparator->compare($this->set([[null, '']]), $this->set([['', null]]), true)->matches);
        $this->assertFalse($this->comparator->compare($this->set([[0, 'x']]), $this->set([[null, 'x']]), true)->matches);
    }

    #[Test]
    public function a_missing_result_set_is_reported(): void
    {
        $result = $this->comparator->compare(['columns' => [], 'rows' => []], $this->set([[1, 'x']]), false);

        $this->assertStringContainsString('aucun jeu de résultats', $result->message);
    }
}
