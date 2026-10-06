<?php

namespace Tests\Unit\Datasets;

use App\Services\Datasets\Column;
use App\Services\Datasets\ColumnType;
use App\Services\Datasets\TypeInferrer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TypeInferrerTest extends TestCase
{
    /**
     * @return array<string, array{list<mixed>, ColumnType}>
     */
    public static function samples(): array
    {
        return [
            'entiers' => [['1', '42', '-7'], ColumnType::Integer],
            'décimaux avec virgule' => [['12,50', '3', '0.5'], ColumnType::Decimal],
            'booléens français' => [['oui', 'non', 'Oui'], ColumnType::Boolean],
            'booléens JSON' => [[true, false], ColumnType::Boolean],
            'dates' => [['2024-01-31', '2024-02-29'], ColumnType::Date],
            'date invalide' => [['2024-02-30'], ColumnType::Text],
            'horodatages' => [['2024-06-01 10:00', '2024-06-02T08:30:15'], ColumnType::DateTime],
            'codes postaux' => [['06000', '75001'], ColumnType::Text],
            'mélange' => [['12', 'abc'], ColumnType::Text],
            'que des NULL' => [[null, null], ColumnType::Text],
        ];
    }

    #[Test]
    #[DataProvider('samples')]
    public function it_picks_the_narrowest_compatible_type(array $values, ColumnType $expected): void
    {
        $column = new Column('c');
        (new TypeInferrer)->infer($column, $values);

        $this->assertSame($expected, $column->type);
    }

    #[Test]
    public function it_measures_nullability_and_decimal_precision(): void
    {
        $column = new Column('prix');
        (new TypeInferrer)->infer($column, ['1234,5', null, '0.125']);

        $this->assertTrue($column->nullable);
        $this->assertSame(3, $column->scale);
        $this->assertSame(4, $column->integerDigits);
    }

    #[Test]
    public function it_casts_values_to_the_inferred_type(): void
    {
        $inferrer = new TypeInferrer;

        $this->assertSame('12.50', $inferrer->cast('12,50', ColumnType::Decimal));
        $this->assertTrue($inferrer->cast('Oui', ColumnType::Boolean));
        $this->assertSame('2024-06-02 08:30:00', $inferrer->cast('2024-06-02T08:30', ColumnType::DateTime));
        $this->assertSame(42, $inferrer->cast(' 42 ', ColumnType::Integer));
        $this->assertNull($inferrer->cast(null, ColumnType::Integer));
    }
}
