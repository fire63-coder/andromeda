<?php

namespace App\Services\Sandbox;

/**
 * Analyse lexicale minimale, commune à tous les dialectes :
 * découpe un script en instructions et extrait les mots-clés hors
 * chaînes, identifiants délimités et commentaires.
 *
 * Gère : 'chaînes' ('' échappé), "identifiants", `identifiants`, [identifiants],
 * -- et /* commentaires *\/, $$corps$$ et $tag$corps$tag$ (PostgreSQL),
 * blocs BEGIN ... END des CREATE TRIGGER (SQLite, MySQL).
 */
class SqlLexer
{
    /**
     * @return list<string> instructions non vides, sans le ";" final
     */
    public function statements(string $sql): array
    {
        $statements = [];
        $current = '';
        $blockDepth = 0;
        $words = [];

        foreach ($this->tokens($sql) as [$type, $value]) {
            if ($type === 'symbol' && $value === ';' && $blockDepth === 0) {
                $this->pushStatement($statements, $current);
                $current = '';
                $words = [];

                continue;
            }

            // Les commentaires et espaces qui précèdent une instruction ne lui appartiennent pas.
            if ($words === [] && ($type === 'space' || $type === 'comment')) {
                continue;
            }

            $current .= $value;

            if ($type !== 'word') {
                continue;
            }

            $word = strtoupper($value);
            $words[] = $word;

            // Les corps de trigger contiennent des ";" : on ne coupe pas avant le END.
            if ($word === 'BEGIN' && $this->isTriggerDefinition($words)) {
                $blockDepth++;
            } elseif ($word === 'CASE' && $blockDepth > 0) {
                $blockDepth++;
            } elseif ($word === 'END' && $blockDepth > 0) {
                $blockDepth--;
            }
        }

        $this->pushStatement($statements, $current);

        return $statements;
    }

    /**
     * Mots (mots-clés, identifiants non délimités, noms de fonctions) en majuscules,
     * dans l'ordre d'apparition, hors chaînes et commentaires.
     *
     * @return list<string>
     */
    public function words(string $sql): array
    {
        $words = [];

        foreach ($this->tokens($sql) as [$type, $value]) {
            if ($type === 'word') {
                $words[] = strtoupper($value);
            }
        }

        return $words;
    }

    /**
     * Identifiants délimités ("x", `x`, [x]) sans leurs délimiteurs, en majuscules,
     * plus les mots contenus dans les crochets (indices de tableau PostgreSQL).
     *
     * @return list<string>
     */
    public function quotedIdentifiers(string $sql): array
    {
        $identifiers = [];

        foreach ($this->tokens($sql) as [$type, $value]) {
            if ($type !== 'quoted') {
                continue;
            }

            $identifiers[] = strtoupper(str_replace(['""', '``'], ['"', '`'], substr($value, 1, -1)));

            // En PostgreSQL, [...] est un indice de tableau : son contenu est une expression.
            if ($value[0] === '[') {
                array_push($identifiers, ...$this->words(substr($value, 1, -1)), ...$this->quotedIdentifiers(substr($value, 1, -1)));
            }
        }

        return $identifiers;
    }

    public function firstWord(string $statement): ?string
    {
        return $this->words($statement)[0] ?? null;
    }

    /**
     * @return \Generator<int, array{0: string, 1: string}> [type, texte]
     *                                                      type : word | string | quoted | comment | space | symbol
     */
    public function tokens(string $sql): \Generator
    {
        $length = strlen($sql);
        $i = 0;

        while ($i < $length) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';

            if (ctype_space($char)) {
                $start = $i;
                while ($i < $length && ctype_space($sql[$i])) {
                    $i++;
                }
                yield ['space', substr($sql, $start, $i - $start)];

                continue;
            }

            if ($char === '-' && $next === '-') {
                $end = strpos($sql, "\n", $i);
                $end = $end === false ? $length : $end;
                yield ['comment', substr($sql, $i, $end - $i)];
                $i = $end;

                continue;
            }

            if ($char === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $end = $end === false ? $length : $end + 2;
                yield ['comment', substr($sql, $i, $end - $i)];
                $i = $end;

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $end = $this->closingQuote($sql, $i, $char);
                yield [$char === "'" ? 'string' : 'quoted', substr($sql, $i, $end - $i)];
                $i = $end;

                continue;
            }

            if ($char === '[') {
                $end = strpos($sql, ']', $i);
                $end = $end === false ? $length : $end + 1;
                yield ['quoted', substr($sql, $i, $end - $i)];
                $i = $end;

                continue;
            }

            if ($char === '$' && preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $sql, $match, 0, $i)) {
                $tag = $match[0];
                $end = strpos($sql, $tag, $i + strlen($tag));
                $end = $end === false ? $length : $end + strlen($tag);
                yield ['string', substr($sql, $i, $end - $i)];
                $i = $end;

                continue;
            }

            if (ctype_alpha($char) || $char === '_' || ord($char) >= 0x80) {
                $start = $i;
                while ($i < $length && (ctype_alnum($sql[$i]) || $sql[$i] === '_' || $sql[$i] === '$' || ord($sql[$i]) >= 0x80)) {
                    $i++;
                }
                yield ['word', substr($sql, $start, $i - $start)];

                continue;
            }

            yield ['symbol', $char];
            $i++;
        }
    }

    private function closingQuote(string $sql, int $start, string $quote): int
    {
        $length = strlen($sql);
        $i = $start + 1;

        while ($i < $length) {
            if ($sql[$i] === $quote) {
                // Guillemet doublé = guillemet échappé.
                if (($sql[$i + 1] ?? '') === $quote) {
                    $i += 2;

                    continue;
                }

                return $i + 1;
            }
            $i++;
        }

        return $length;
    }

    /**
     * @param  list<string>  $words
     */
    private function isTriggerDefinition(array $words): bool
    {
        return ($words[0] ?? null) === 'CREATE' && in_array('TRIGGER', $words, true);
    }

    /**
     * @param  list<string>  $statements
     */
    private function pushStatement(array &$statements, string $statement): void
    {
        if ($this->words($statement) !== []) {
            $statements[] = trim($statement);
        }
    }
}
