<?php

/*
|--------------------------------------------------------------------------
| Exécuteur SQLite isolé
|--------------------------------------------------------------------------
|
| Lancé dans un processus PHP séparé par App\Services\Sandbox\Drivers\SqliteDriver
| (sans Laravel, avec open_basedir, memory_limit et un timeout imposé par le parent).
| SQLite n'offrant pas de timeout par requête via PDO, c'est le parent qui tue
| le processus si la requête dure trop longtemps.
|
| Mode « query » (défaut) — exécute la requête d'un élève, toujours annulée :
|   entrée : database, readonly, statements[], checks{nom: sql}, max_rows
| Mode « build » — crée une base modèle à partir du script d'un jeu de données :
|   entrée : database, script
| Sortie (stdout, JSON) : voir App\Services\Sandbox\QueryResult::fromArray()
|
*/

$started = hrtime(true);
$input = json_decode((string) stream_get_contents(STDIN), true);

$output = static function (array $payload) use ($started): never {
    $payload['duration_ms'] ??= (int) ((hrtime(true) - $started) / 1_000_000);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    exit(0);
};

$normalize = static function (mixed $value): mixed {
    if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
        return '0x'.strtoupper(bin2hex($value)); // BLOB
    }

    return $value;
};

$cleanError = static fn (PDOException $e): string => preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $e->getMessage());

/**
 * @return array{columns: list<string>, rows: list<list<mixed>>, truncated: bool}
 */
$fetch = static function (PDOStatement $statement, int $maxRows) use ($normalize): array {
    $columns = [];
    for ($i = 0; $i < $statement->columnCount(); $i++) {
        $columns[] = $statement->getColumnMeta($i)['name'] ?? "col{$i}";
    }

    $rows = [];
    $truncated = false;
    while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
        if (count($rows) >= $maxRows) {
            $truncated = true;
            break;
        }
        $rows[] = array_map($normalize, $row);
    }
    $statement->closeCursor();

    return ['columns' => $columns, 'rows' => $rows, 'truncated' => $truncated];
};

if (! is_array($input) || ! isset($input['database']) || (($input['mode'] ?? 'query') === 'query' && ! isset($input['statements']))) {
    $output(['success' => false, 'error' => 'Entrée invalide.', 'error_type' => 'internal']);
}

if (($input['mode'] ?? 'query') === 'build') {
    try {
        $pdo = new PDO('sqlite:'.$input['database'], null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READWRITE | PDO::SQLITE_OPEN_CREATE,
        ]);
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->beginTransaction();
        $pdo->exec((string) ($input['script'] ?? ''));
        $pdo->commit();
    } catch (PDOException $e) {
        $output(['success' => false, 'error' => $cleanError($e), 'error_type' => 'sql']);
    }

    $output(['success' => true]);
}

try {
    $pdo = new PDO('sqlite:'.$input['database'], null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::SQLITE_ATTR_OPEN_FLAGS => $input['readonly'] ? PDO::SQLITE_OPEN_READONLY : PDO::SQLITE_OPEN_READWRITE,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->beginTransaction();
} catch (PDOException $e) {
    $output(['success' => false, 'error' => $cleanError($e), 'error_type' => 'internal']);
}

$maxRows = (int) ($input['max_rows'] ?? 500);
$result = ['success' => true, 'columns' => [], 'rows' => [], 'truncated' => false, 'affected_rows' => null, 'checks' => []];

try {
    foreach ($input['statements'] as $sql) {
        $statement = $pdo->query($sql);

        if ($statement->columnCount() > 0) {
            $result = [...$result, ...$fetch($statement, $maxRows)];
        } else {
            $result['affected_rows'] = ($result['affected_rows'] ?? 0) + $statement->rowCount();
        }
    }

    $result['duration_ms'] = (int) ((hrtime(true) - $started) / 1_000_000);

    foreach ($input['checks'] ?? [] as $name => $sql) {
        $check = $fetch($pdo->query($sql), $maxRows);
        $result['checks'][$name] = ['columns' => $check['columns'], 'rows' => $check['rows']];
    }
} catch (PDOException $e) {
    $result = ['success' => false, 'error' => $cleanError($e), 'error_type' => 'sql'];
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

$output($result);
