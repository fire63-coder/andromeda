<?php

namespace App\Services\Datasets;

/**
 * Déduit le type d'une colonne à partir de ses valeurs (CSV, JSON), puis convertit
 * les valeurs vers ce type. Le type retenu est le plus étroit compatible avec
 * toutes les valeurs non nulles.
 */
class TypeInferrer
{
    private const TRUE_VALUES = ['true', 't', 'yes', 'oui', 'vrai'];

    private const FALSE_VALUES = ['false', 'f', 'no', 'non', 'faux'];

    /**
     * @param  list<mixed>  $values
     */
    public function infer(Column $column, array $values): void
    {
        $candidates = [ColumnType::Boolean, ColumnType::Integer, ColumnType::Decimal, ColumnType::Date, ColumnType::DateTime];
        $nonNull = 0;

        foreach ($values as $value) {
            if ($value === null) {
                $column->nullable = true;

                continue;
            }

            $nonNull++;
            $string = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $column->maxLength = max($column->maxLength, mb_strlen($string));

            $candidates = array_values(array_filter($candidates, fn (ColumnType $type) => $this->accepts($type, $value, $string)));

            if (in_array(ColumnType::Decimal, $candidates, true)) {
                $this->measureDecimal($column, $string);
            }
        }

        $column->nullable = $nonNull < count($values);
        $column->type = $nonNull === 0 ? ColumnType::Text : ($candidates[0] ?? ColumnType::Text);
    }

    /**
     * Mesure longueurs et précision d'une colonne dont le type est déjà connu (dumps SQL).
     *
     * @param  list<mixed>  $values
     */
    public function measure(Column $column, array $values): void
    {
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $string = (string) $value;
            $column->maxLength = max($column->maxLength, mb_strlen($string));

            if ($column->type === ColumnType::Decimal && is_numeric($string)) {
                $this->measureDecimal($column, $string);
            }
        }
    }

    public function cast(mixed $value, ColumnType $type): mixed
    {
        if ($value === null) {
            return null;
        }

        $string = is_bool($value) ? ($value ? 'true' : 'false') : trim((string) $value);

        return match ($type) {
            ColumnType::Integer => (int) $string,
            ColumnType::Decimal => $this->normalizeDecimal($string),
            ColumnType::Boolean => in_array(strtolower($string), ['1', ...self::TRUE_VALUES], true),
            ColumnType::Date => substr($this->normalizeDate($string), 0, 10),
            ColumnType::DateTime => $this->normalizeDate($string),
            ColumnType::Text => is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : (string) $value,
        };
    }

    private function accepts(ColumnType $type, mixed $value, string $string): bool
    {
        $trimmed = trim($string);

        return match ($type) {
            ColumnType::Boolean => is_bool($value) || in_array(strtolower($trimmed), [...self::TRUE_VALUES, ...self::FALSE_VALUES], true),
            // Pas de zéros non significatifs : "007" ou un code postal restent du texte.
            ColumnType::Integer => (bool) preg_match('/^-?(0|[1-9]\d{0,17})$/', $trimmed),
            ColumnType::Decimal => (bool) preg_match('/^-?(0|[1-9]\d*)([.,]\d+)?$/', $trimmed),
            ColumnType::Date => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed) && $this->validDate($trimmed),
            ColumnType::DateTime => (bool) preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $trimmed) && $this->validDate(substr($trimmed, 0, 10)),
            ColumnType::Text => true,
        };
    }

    private function measureDecimal(Column $column, string $string): void
    {
        [$integer, $fraction] = array_pad(explode('.', ltrim(str_replace(',', '.', trim($string)), '-')), 2, '');
        $column->scale = max($column->scale, min(6, strlen($fraction)));
        $column->integerDigits = max($column->integerDigits, strlen($integer));
    }

    private function normalizeDecimal(string $string): string
    {
        return str_replace(',', '.', $string);
    }

    private function normalizeDate(string $string): string
    {
        return str_replace('T', ' ', strlen($string) === 16 ? $string.':00' : $string);
    }

    private function validDate(string $date): bool
    {
        [$year, $month, $day] = array_map('intval', explode('-', $date));

        return checkdate($month, $day, $year);
    }
}
