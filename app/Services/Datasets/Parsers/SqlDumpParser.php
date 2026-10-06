<?php

namespace App\Services\Datasets\Parsers;

use App\Services\Datasets\Column;
use App\Services\Datasets\ColumnType;
use App\Services\Datasets\ImportException;
use App\Services\Datasets\Table;
use App\Services\Sandbox\Exceptions\QueryRejected;
use App\Services\Sandbox\QueryGuard;
use App\Services\Sandbox\SqliteProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;

/**
 * Dump SQL en syntaxe portable / SQLite : validé par le garde-fou, chargé dans une base
 * SQLite temporaire PAR LE PROCESSUS ISOLÉ, puis relu (tables, types déclarés, clés, lignes).
 */
class SqlDumpParser
{
    public function __construct(
        private readonly QueryGuard $guard,
    ) {}

    /**
     * @return list<Table>
     */
    public function parse(string $script): array
    {
        try {
            $this->guard->inspectScript($script);
        } catch (QueryRejected $e) {
            throw new ImportException("Dump refusé : {$e->getMessage()}");
        }

        $directory = config('sandbox.drivers.sqlite.path').'/imports';
        File::ensureDirectoryExists($directory);
        $database = $directory.'/'.Str::uuid().'.sqlite';

        try {
            $result = SqliteProcess::fromConfig()->build($database, $script);

            if (! $result->success) {
                throw new ImportException("Le dump ne s'exécute pas (syntaxe SQLite / SQL standard attendue) : {$result->error}");
            }

            return $this->read($database);
        } finally {
            @unlink($database);
        }
    }

    /**
     * @return list<Table>
     */
    private function read(string $database): array
    {
        $pdo = new PDO('sqlite:'.$database, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
        ]);

        $names = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY rowid")
            ->fetchAll(PDO::FETCH_COLUMN);

        if ($names === []) {
            throw new ImportException('Le dump ne crée aucune table.');
        }

        return array_map(function (string $name) use ($pdo) {
            $quoted = '"'.str_replace('"', '""', $name).'"';

            $foreignKeys = [];
            foreach ($pdo->query("PRAGMA foreign_key_list({$quoted})")->fetchAll(PDO::FETCH_ASSOC) as $fk) {
                $foreignKeys[$fk['from']] = $fk['table'].'.'.($fk['to'] ?? 'id');
            }

            $columns = array_map(function (array $info) use ($foreignKeys) {
                $column = new Column(
                    $info['name'],
                    nullable: ! $info['notnull'],
                    primary: (bool) $info['pk'],
                    references: $foreignKeys[$info['name']] ?? null,
                );
                // Type déclaré conservé comme indice ; l'assembleur le confirme sur les données.
                $column->type = ColumnType::fromDeclared((string) $info['type']) ?? ColumnType::Text;

                return $column;
            }, $pdo->query("PRAGMA table_info({$quoted})")->fetchAll(PDO::FETCH_ASSOC));

            $rows = $pdo->query("SELECT * FROM {$quoted}")->fetchAll(PDO::FETCH_NUM);

            return new Table($name, $columns, $rows);
        }, $names);
    }
}
