<?php

namespace App\Services\Evaluation;

/**
 * Rend comparables des valeurs venant de moteurs différents :
 * PostgreSQL renvoie les NUMERIC en chaînes ("24.90"), SQLite en flottants (24.9),
 * les booléens peuvent valoir true / 1 / 't'...
 */
class ResultNormalizer
{
    public const DEFAULT_FLOAT_TOLERANCE = 0.000001;

    /**
     * @param  array{float_tolerance?: float, case_sensitive?: bool, trim_strings?: bool}  $options
     */
    public function __construct(private readonly array $options = []) {}

    public function value(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $string = (string) $value;

        if (is_numeric($string)) {
            return (float) $string;
        }

        if ($this->options['trim_strings'] ?? true) {
            // Les colonnes CHAR(n) sont complétées par des espaces selon les moteurs.
            $string = rtrim($string, ' ');
        }

        return ($this->options['case_sensitive'] ?? true) ? $string : mb_strtolower($string);
    }

    /**
     * @param  list<mixed>  $row
     * @return list<mixed>
     */
    public function row(array $row): array
    {
        return array_map($this->value(...), array_values($row));
    }

    public function equals(mixed $a, mixed $b): bool
    {
        if (is_float($a) && is_float($b)) {
            // Tolérance absolue (ex. 0.01 = au centime), plus une marge relative infime
            // pour les erreurs d'arrondi binaire sur les grands nombres.
            return abs($a - $b) <= $this->tolerance() + 1e-9 * max(abs($a), abs($b));
        }

        return $a === $b;
    }

    /**
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     */
    public function rowsEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $index => $value) {
            if (! $this->equals($value, $b[$index])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Clé de regroupement d'une ligne normalisée : les valeurs non numériques sont
     * comparées exactement, les flottants sont départagés ensuite avec la tolérance.
     *
     * @param  list<mixed>  $row
     */
    public function bucketKey(array $row): string
    {
        return json_encode(array_map(fn (mixed $value) => is_float($value) ? '#num' : $value, $row), JSON_UNESCAPED_UNICODE);
    }

    private function tolerance(): float
    {
        return (float) ($this->options['float_tolerance'] ?? self::DEFAULT_FLOAT_TOLERANCE);
    }
}
