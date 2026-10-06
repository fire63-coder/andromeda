<?php

namespace App\Services\Datasets\Parsers;

use App\Services\Datasets\Column;
use App\Services\Datasets\ImportException;
use App\Services\Datasets\Table;
use SplFileObject;

/**
 * Un fichier CSV = une table. Séparateur détecté automatiquement (, ; tabulation |),
 * BOM UTF-8 retiré, cellules vides converties en NULL.
 */
class CsvParser
{
    private const DELIMITERS = [',', ';', "\t", '|'];

    /** @var list<string> */
    public array $warnings = [];

    public function parse(string $path, string $tableName, ?string $delimiter = null, bool $hasHeader = true): Table
    {
        $file = new SplFileObject($path);
        $delimiter ??= $this->detectDelimiter($path);
        $file->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::READ_AHEAD | SplFileObject::DROP_NEW_LINE);
        $file->setCsvControl($delimiter, '"', '');

        $header = null;
        $rows = [];

        foreach ($file as $lineNumber => $fields) {
            if (! is_array($fields) || $fields === [null]) {
                continue;
            }

            if ($lineNumber === 0) {
                $fields[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $fields[0]);
            }

            $fields = array_map(fn ($value) => $this->toUtf8($value), $fields);

            if ($header === null) {
                $header = $hasHeader ? $fields : array_map(fn (int $i) => 'col'.($i + 1), array_keys($fields));

                if ($hasHeader) {
                    continue;
                }
            }

            if (count($fields) !== count($header)) {
                $this->warnings[] = "{$tableName} : la ligne ".($lineNumber + 1).' a '.count($fields).' champs au lieu de '.count($header).' (complétée ou tronquée).';
                $fields = array_slice(array_pad($fields, count($header), null), 0, count($header));
            }

            $rows[] = array_map(fn ($value) => $value === null || trim($value) === '' ? null : $value, $fields);
        }

        if ($header === null) {
            throw new ImportException("Le fichier {$tableName} est vide.");
        }

        return new Table($tableName, array_map(fn ($name) => new Column((string) $name), $header), $rows);
    }

    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $sample = (string) fgets($handle, 64 * 1024);
        fclose($handle);

        $counts = array_map(fn (string $delimiter) => substr_count($sample, $delimiter), self::DELIMITERS);

        return max($counts) > 0 ? self::DELIMITERS[array_search(max($counts), $counts, true)] : ',';
    }

    private function toUtf8(?string $value): ?string
    {
        if ($value === null || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        // Exports Excel fréquents en Windows-1252.
        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }
}
