<?php

namespace App\Services\Datasets;

use Illuminate\Support\Str;

/**
 * Noms de tables et de colonnes simples à écrire pour un élève, valides dans tous les moteurs :
 * snake_case ASCII, pas de chiffre en tête, pas de mot réservé, pas de doublon.
 */
class IdentifierSanitizer
{
    /** Mots réservés dans au moins un des moteurs cibles. */
    private const RESERVED = [
        'all', 'and', 'any', 'as', 'asc', 'between', 'by', 'case', 'check', 'column', 'constraint', 'create',
        'cross', 'current_date', 'current_time', 'current_user', 'default', 'delete', 'desc', 'distinct', 'drop',
        'else', 'end', 'except', 'exists', 'false', 'fetch', 'for', 'foreign', 'from', 'full', 'grant', 'group',
        'groups', 'having', 'in', 'index', 'inner', 'insert', 'intersect', 'into', 'is', 'join', 'key', 'left',
        'like', 'limit', 'natural', 'not', 'null', 'offset', 'on', 'or', 'order', 'outer', 'over', 'partition',
        'primary', 'range', 'rank', 'references', 'right', 'row', 'rows', 'select', 'set', 'table', 'then', 'to',
        'true', 'union', 'unique', 'update', 'user', 'using', 'values', 'when', 'where', 'window', 'with',
    ];

    /** @var list<string> */
    public array $warnings = [];

    public function sanitize(string $name, string $fallback = 'col'): string
    {
        // « CustomerID » → customer_id, « Date de commande » → date_de_commande
        $clean = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', Str::ascii($name));
        $clean = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($clean)), '_');

        if ($clean === '' || $clean === null) {
            $clean = $fallback;
        }

        if (ctype_digit($clean[0])) {
            $clean = $fallback.'_'.$clean;
        }

        if (in_array($clean, self::RESERVED, true)) {
            $this->warnings[] = "« {$name} » est un mot réservé SQL : renommé en « {$clean}_value ».";
            $clean .= '_value';
        } elseif ($clean !== strtolower($name)) {
            $this->warnings[] = "« {$name} » renommé en « {$clean} ».";
        }

        return substr($clean, 0, 60);
    }

    /**
     * @param  list<string>  $names
     * @return list<string> noms nettoyés et uniques, dans le même ordre
     */
    public function sanitizeAll(array $names, string $fallback = 'col'): array
    {
        $seen = [];

        return array_map(function (string $name, int $index) use (&$seen, $fallback) {
            $clean = $this->sanitize($name, $fallback.($index + 1));
            $unique = $clean;
            $suffix = 2;

            while (isset($seen[$unique])) {
                $unique = $clean.'_'.$suffix++;
            }

            if ($unique !== $clean) {
                $this->warnings[] = "Nom en double « {$name} » : renommé en « {$unique} ».";
            }

            $seen[$unique] = true;

            return $unique;
        }, $names, array_keys($names));
    }
}
