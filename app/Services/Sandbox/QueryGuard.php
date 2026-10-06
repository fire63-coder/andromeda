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
        'SET', 'RESET', 'GRANT', 'REVOKE', 'DO', 'CALL', 'EXEC', 'EXECUTE', 'PREPARE', 'DEALLOCATE',
        'LOCK', 'CHECKPOINT', 'CLUSTER', 'REINDEX', 'DISCARD', 'SECURITY', 'IMPORT', 'REFRESH',
    ];

    /** Mots refusés où qu'ils apparaissent (fonctions dangereuses, SQL dynamique). */
    private const FORBIDDEN_WORDS = [
        'LOAD_EXTENSION', 'SET_CONFIG', 'PG_SLEEP_FOR', 'PG_READ_FILE', 'PG_READ_BINARY_FILE', 'PG_LS_DIR',
        'PG_STAT_FILE', 'LO_IMPORT', 'LO_EXPORT', 'DBLINK', 'DBLINK_EXEC', 'QUERY_TO_XML',
        'QUERY_TO_XML_AND_XMLSCHEMA', 'CURSOR_TO_XML', 'PG_TERMINATE_BACKEND', 'PG_CANCEL_BACKEND',
        'PG_RELOAD_CONF', 'PG_ADVISORY_LOCK', 'PG_ADVISORY_XACT_LOCK',
        'OUTFILE', 'DUMPFILE', 'LOAD_FILE', 'XP_CMDSHELL', 'OPENROWSET', 'UTL_FILE',
    ];

    /** Objets dont la création est refusée même quand le DDL est autorisé. */
    private const FORBIDDEN_DDL_OBJECTS = [
        'DATABASE', 'SCHEMA', 'ROLE', 'USER', 'EXTENSION', 'LANGUAGE', 'SERVER', 'TABLESPACE',
        'FUNCTION', 'PROCEDURE', 'EVENT', 'SUBSCRIPTION', 'PUBLICATION', 'POLICY', 'CAST', 'OPERATOR',
    ];

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
            $kind = $this->classify($words);

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
            $this->classify($this->lexer->words($statement));
        }
    }

    /**
     * @param  list<string>  $words
     */
    private function classify(array $words): StatementKind
    {
        $first = $words[0];

        if (in_array($first, self::FORBIDDEN_STATEMENTS, true)) {
            throw new QueryRejected("L'instruction {$first} n'est pas autorisée dans le bac à sable.");
        }

        foreach ($words as $word) {
            if (in_array($word, self::FORBIDDEN_WORDS, true)) {
                throw new QueryRejected("L'utilisation de {$word} n'est pas autorisée dans le bac à sable.");
            }
        }

        if (in_array($first, self::DDL_WORDS, true)) {
            $this->checkDdlObject($words);

            return StatementKind::Ddl;
        }

        if (in_array($first, self::DML_WORDS, true)) {
            return StatementKind::Dml;
        }

        if (in_array($first, self::SELECT_WORDS, true)) {
            // WITH ... DELETE / INSERT (CTE modifiante PostgreSQL) = DML.
            if ($first === 'WITH' && array_intersect($words, ['INSERT', 'UPDATE', 'DELETE', 'MERGE']) !== []) {
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
