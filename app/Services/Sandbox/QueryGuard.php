<?php

namespace App\Services\Sandbox;

use App\Services\Sandbox\Exceptions\QueryRejected;

/**
 * Première ligne de défense avant toute exécution : refuse ce qu'un exercice
 * n'autorise pas et ce qui pourrait sortir de la sandbox.
 *
 * Ce n'est PAS la seule protection : les moteurs exécutent aussi dans un
 * processus ou un compte sans privilèges, avec timeout et ROLLBACK systématique.
 */
class QueryGuard
{
    /** Premier mot d'instruction toujours refusé (contrôle de transaction, session, administration). */
    private const FORBIDDEN_STATEMENTS = [
        'ATTACH', 'DETACH', 'PRAGMA', 'VACUUM', 'LOAD', 'COPY', 'LISTEN', 'NOTIFY', 'UNLISTEN',
        'BEGIN', 'START', 'COMMIT', 'ROLLBACK', 'END', 'ABORT', 'SAVEPOINT', 'RELEASE',
        'SET', 'RESET', 'GRANT', 'REVOKE', 'DO', 'EXEC', 'EXECUTE', 'PREPARE', 'DEALLOCATE',
        'LOCK', 'CHECKPOINT', 'CLUSTER', 'REINDEX', 'DISCARD', 'SECURITY', 'IMPORT', 'REFRESH',
    ];

    /** Mots refusés où qu'ils apparaissent (fonctions dangereuses, SQL dynamique). */
    private const FORBIDDEN_WORDS = [
        'LOAD_EXTENSION', 'SET_CONFIG', 'PG_SLEEP_FOR', 'PG_READ_FILE', 'PG_READ_BINARY_FILE', 'PG_LS_DIR',
        'PG_STAT_FILE', 'LO_IMPORT', 'LO_EXPORT', 'DBLINK', 'DBLINK_EXEC', 'QUERY_TO_XML',
        'QUERY_TO_XML_AND_XMLSCHEMA', 'CURSOR_TO_XML', 'PG_TERMINATE_BACKEND', 'PG_CANCEL_BACKEND',
        'PG_RELOAD_CONF', 'PG_ADVISORY_LOCK', 'PG_ADVISORY_XACT_LOCK',
        'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'XP_CMDSHELL', 'OPENROWSET', 'UTL_FILE',
        'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'RELEASE_ALL_LOCKS',
    ];

    /** Objets dont la création est refusée même quand le DDL est autorisé. */
    private const FORBIDDEN_DDL_OBJECTS = [
        'DATABASE', 'SCHEMA', 'ROLE', 'USER', 'EXTENSION', 'LANGUAGE', 'SERVER', 'TABLESPACE',
        'FUNCTION', 'PROCEDURE', 'EVENT', 'SUBSCRIPTION', 'PUBLICATION', 'POLICY', 'CAST', 'OPERATOR',
    ];

    /** Langages autorisés pour les fonctions et procédures (PostgreSQL, langages « de confiance »). */
    private const ROUTINE_LANGUAGES = ['PLPGSQL', 'SQL'];

    /**
     * Mots qui, dans un corps PL/pgSQL, précèdent le début d'une instruction.
     * BEGIN y ouvre un bloc (pas une transaction) et END le ferme : ils restent permis.
     */
    private const BODY_STATEMENT_OPENERS = ['BEGIN', 'THEN', 'ELSE', 'LOOP', 'DECLARE'];

    /**
     * Conditions d'exception qui permettraient d'intercepter l'annulation pour dépassement
     * de temps (57014 query_canceled) et de boucler indéfiniment.
     */
    private const FORBIDDEN_CONDITIONS = ['QUERY_CANCELED', 'ADMIN_SHUTDOWN', 'CRASH_SHUTDOWN', 'CANNOT_CONNECT_NOW', 'OPERATOR_INTERVENTION'];

    private const DML_WORDS = ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE', 'UPSERT', 'TRUNCATE'];

    private const DDL_WORDS = ['CREATE', 'ALTER', 'DROP', 'RENAME', 'COMMENT'];

    private const SELECT_WORDS = ['SELECT', 'WITH', 'VALUES', 'TABLE', 'EXPLAIN'];

    public function __construct(private readonly SqlLexer $lexer) {}

    /**
     * @param  array{allowed_statements?: list<string>, max_statements?: int, required_keywords?: list<string>, forbidden_keywords?: list<string>}  $options
     *
     * @throws QueryRejected
     */
    public function inspect(string $sql, array $options = []): GuardedQuery
    {
        if (mb_strlen($sql) > config('sandbox.max_query_length')) {
            throw new QueryRejected('La requête est trop longue.');
        }

        $statements = $this->lexer->statements($sql);

        if ($statements === []) {
            throw new QueryRejected('La requête est vide.');
        }

        $allowed = array_map(
            fn (string $kind) => StatementKind::from($kind),
            $options['allowed_statements'] ?? [StatementKind::Select->value],
        );
        $maxStatements = $options['max_statements'] ?? ($allowed === [StatementKind::Select] ? 1 : 20);

        if (count($statements) > $maxStatements) {
            throw new QueryRejected($maxStatements === 1
                ? 'Une seule instruction est attendue : retirez les instructions supplémentaires.'
                : "Au plus {$maxStatements} instructions sont autorisées pour cet exercice.");
        }

        $kinds = [];
        $allWords = [];

        foreach ($statements as $statement) {
            $words = $this->lexer->words($statement);
            $allWords = array_merge($allWords, $words);
            $kind = $this->classify($words, $statement);

            // Mots-clés imposés ou interdits : le corps d'une fonction compte aussi (IF, LOOP...).
            if ($kind === StatementKind::Routine) {
                $allWords = array_merge($allWords, $this->routineBodyWords($statement));
            }

            if (! in_array($kind, $allowed, true)) {
                throw new QueryRejected('Cet exercice n\'accepte que les '.implode(' et les ', array_map(
                    fn (StatementKind $k) => $k->label(), $allowed,
                )).'.');
            }

            $kinds[] = $kind;
        }

        $this->checkRequiredAndForbidden($allWords, $options);

        return new GuardedQuery($statements, $kinds);
    }

    /**
     * Valide un script de jeu de données (schéma + données, écrit par un formateur ou importé)
     * avant de le charger dans une sandbox : mêmes interdits de sécurité, sans limite de taille.
     *
     * @throws QueryRejected
     */
    public function inspectScript(string $sql): void
    {
        foreach ($this->lexer->statements($sql) as $statement) {
            // Le script est chargé par le compte propriétaire : pas de code stocké qui s'exécuterait avec ses droits.
            if ($this->classify($this->lexer->words($statement), $statement) === StatementKind::Routine) {
                throw new QueryRejected('Un jeu de données ne peut pas contenir de fonctions, procédures ou CALL.');
            }
        }
    }

    /**
     * @param  list<string>  $words
     */
    private function classify(array $words, string $statement): StatementKind
    {
        $first = $words[0];

        if (in_array($first, self::FORBIDDEN_STATEMENTS, true)) {
            throw new QueryRejected("L'instruction {$first} n'est pas autorisée dans le bac à sable.");
        }

        // Les identifiants délimités comptent aussi : "pg_terminate_backend"(...) appelle la même fonction.
        foreach ([...$words, ...$this->lexer->quotedIdentifiers($statement)] as $word) {
            if (in_array($word, self::FORBIDDEN_WORDS, true)) {
                throw new QueryRejected("L'utilisation de {$word} n'est pas autorisée dans le bac à sable.");
            }
        }

        if ($this->isRoutine($words)) {
            $this->checkRoutine($words, $statement);

            return StatementKind::Routine;
        }

        if (in_array($first, self::DDL_WORDS, true)) {
            $this->checkDdlObject($words);

            return StatementKind::Ddl;
        }

        if (in_array($first, self::DML_WORDS, true)) {
            return StatementKind::Dml;
        }

        if (in_array($first, self::SELECT_WORDS, true)) {
            // WITH ... DELETE / INSERT (CTE modifiante PostgreSQL) = DML ;
            // EXPLAIN ANALYZE exécute réellement l'instruction expliquée.
            if (in_array($first, ['WITH', 'EXPLAIN'], true) && array_intersect($words, ['INSERT', 'UPDATE', 'DELETE', 'MERGE', 'REPLACE']) !== []) {
                return StatementKind::Dml;
            }

            return StatementKind::Select;
        }

        throw new QueryRejected("Instruction non reconnue : « {$first} ». Vérifiez l'orthographe du premier mot-clé.");
    }

    /**
     * @param  list<string>  $words
     */
    private function checkDdlObject(array $words): void
    {
        // CREATE [OR REPLACE] [TEMP|TEMPORARY|UNIQUE|MATERIALIZED|...] <objet>
        foreach (array_slice($words, 1, 4) as $word) {
            if (in_array($word, self::FORBIDDEN_DDL_OBJECTS, true)) {
                throw new QueryRejected("La création ou la modification d'objets {$word} n'est pas autorisée ici.");
            }
        }
    }

    /**
     * CREATE [OR REPLACE] FUNCTION | PROCEDURE, DROP FUNCTION | PROCEDURE, CALL.
     *
     * @param  list<string>  $words
     */
    private function isRoutine(array $words): bool
    {
        if ($words[0] === 'CALL') {
            return true;
        }

        $head = array_slice($words, 1, 3);

        return in_array($words[0], ['CREATE', 'DROP'], true)
            && (in_array('FUNCTION', $head, true) || in_array('PROCEDURE', $head, true))
            && ! in_array('TRIGGER', $head, true);
    }

    /**
     * Fonctions et procédures PostgreSQL : langages de confiance uniquement, corps entre $$,
     * aucune clause SET (qui lèverait statement_timeout), corps analysé instruction par instruction.
     *
     * @param  list<string>  $words  mots hors chaînes (l'en-tête)
     */
    private function checkRoutine(array $words, string $statement): void
    {
        if ($words[0] !== 'CREATE') {
            return; // DROP FUNCTION / CALL : seuls les interdits généraux s'appliquent.
        }

        foreach (['SET' => 'Une fonction ne peut pas modifier les paramètres de session (clause SET).',
            'BEGIN' => 'Écrivez le corps de la fonction entre $$ ... $$ (la forme BEGIN ATOMIC n\'est pas prise en charge).',
            'TRANSFORM' => 'La clause TRANSFORM n\'est pas autorisée.'] as $word => $message) {
            if (in_array($word, $words, true)) {
                throw new QueryRejected($message);
            }
        }

        $languageIndex = array_search('LANGUAGE', $words, true);

        if ($languageIndex !== false && ! in_array($words[$languageIndex + 1] ?? null, self::ROUTINE_LANGUAGES, true)) {
            throw new QueryRejected('Seuls les langages plpgsql et sql sont autorisés (LANGUAGE plpgsql ou LANGUAGE sql, sans guillemets).');
        }

        $previousWord = null;

        foreach ($this->lexer->tokens($statement) as [$type, $value]) {
            if ($type === 'word') {
                $previousWord = strtoupper($value);
            } elseif ($type === 'string' && str_starts_with($value, '$')) {
                $this->checkRoutineBody($this->dollarQuotedContent($value));
            } elseif ($type === 'string' && $previousWord === 'AS') {
                throw new QueryRejected('Écrivez le corps de la fonction entre $$ ... $$ plutôt qu\'entre apostrophes.');
            }
        }
    }

    /**
     * Analyse un corps PL/pgSQL ou SQL : interdits généraux, pas de SQL dynamique (EXECUTE),
     * pas d'instruction de session ou de transaction, pas d'objets interdits, pas d'interception
     * de l'annulation pour dépassement de temps.
     */
    private function checkRoutineBody(string $body): void
    {
        $atStatementStart = true;
        $ddlWindow = 0;
        $previous = null;

        foreach ($this->lexer->tokens($body) as [$type, $value]) {
            if ($type === 'space' || $type === 'comment') {
                continue;
            }

            if ($type === 'string') {
                if (str_starts_with($value, '$')) {
                    $this->checkRoutineBody($this->dollarQuotedContent($value));
                } elseif ($previous === 'SQLSTATE' && str_starts_with(trim($value, "'"), '57')) {
                    throw new QueryRejected('Une fonction ne peut pas intercepter l\'annulation d\'une requête (SQLSTATE 57xxx).');
                }
                $atStatementStart = false;
                $previous = null;

                continue;
            }

            if ($type === 'quoted') {
                foreach ($this->lexer->quotedIdentifiers($value) as $identifier) {
                    if (in_array($identifier, [...self::FORBIDDEN_WORDS, 'EXECUTE', ...self::FORBIDDEN_CONDITIONS], true)) {
                        throw new QueryRejected("L'utilisation de {$identifier} n'est pas autorisée dans une fonction du bac à sable.");
                    }
                }
                $atStatementStart = false;
                $previous = null;

                continue;
            }

            if ($type === 'symbol') {
                // ";" termine une instruction ; ">>" termine une étiquette <<bloc>>.
                $atStatementStart = $value === ';' || ($value === '>' && $previous === '>');
                $previous = $value;

                continue;
            }

            $word = strtoupper($value);

            if (in_array($word, self::FORBIDDEN_WORDS, true)) {
                throw new QueryRejected("L'utilisation de {$word} n'est pas autorisée dans le bac à sable.");
            }

            if ($word === 'EXECUTE') {
                throw new QueryRejected('Le SQL dynamique (EXECUTE) n\'est pas autorisé dans les fonctions du bac à sable.');
            }

            if (in_array($word, self::FORBIDDEN_CONDITIONS, true)) {
                throw new QueryRejected("Une fonction ne peut pas intercepter la condition {$word}.");
            }

            if ($atStatementStart) {
                if (in_array($word, self::FORBIDDEN_STATEMENTS, true) && ! in_array($word, ['BEGIN', 'END'], true)) {
                    throw new QueryRejected("L'instruction {$word} n'est pas autorisée dans une fonction du bac à sable.");
                }

                if (in_array($word, self::DDL_WORDS, true)) {
                    $ddlWindow = 4;
                }
            } elseif ($ddlWindow > 0) {
                if (in_array($word, self::FORBIDDEN_DDL_OBJECTS, true)) {
                    throw new QueryRejected("La création ou la modification d'objets {$word} n'est pas autorisée dans une fonction.");
                }
                $ddlWindow--;
            }

            $atStatementStart = in_array($word, self::BODY_STATEMENT_OPENERS, true);
            $previous = $word;
        }
    }

    /**
     * @return list<string>
     */
    private function routineBodyWords(string $statement): array
    {
        $words = [];

        foreach ($this->lexer->tokens($statement) as [$type, $value]) {
            if ($type === 'string' && str_starts_with($value, '$')) {
                array_push($words, ...$this->lexer->words($this->dollarQuotedContent($value)));
            }
        }

        return $words;
    }

    private function dollarQuotedContent(string $literal): string
    {
        preg_match('/^\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $literal, $match);
        $tag = $match[0] ?? '$$';

        return str_ends_with($literal, $tag) && strlen($literal) >= 2 * strlen($tag)
            ? substr($literal, strlen($tag), -strlen($tag))
            : substr($literal, strlen($tag));
    }

    /**
     * @param  list<string>  $words
     * @param  array{required_keywords?: list<string>, forbidden_keywords?: list<string>}  $options
     */
    private function checkRequiredAndForbidden(array $words, array $options): void
    {
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
