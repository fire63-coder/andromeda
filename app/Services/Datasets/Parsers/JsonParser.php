<?php

namespace App\Services\Datasets\Parsers;

use App\Services\Datasets\Column;
use App\Services\Datasets\ImportException;
use App\Services\Datasets\Table;

/**
 * Formats acceptés :
 * - [ {…}, {…} ]                          → une table (nommée d'après le fichier)
 * - { "clients": [ {…} ], "commandes": [ {…} ] } → une table par clé
 * Les valeurs imbriquées (objets, listes) sont stockées en texte JSON.
 */
class JsonParser
{
    /**
     * @return list<Table>
     */
    public function parse(string $contents, string $defaultName): array
    {
        try {
            $data = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ImportException("JSON invalide : {$e->getMessage()}.");
        }

        if (is_array($data) && array_is_list($data)) {
            return [$this->table($defaultName, $data)];
        }

        if (is_array($data) && $data !== [] && collect($data)->every(fn ($rows) => is_array($rows) && array_is_list($rows))) {
            return array_values(array_map(fn (string $name, array $rows) => $this->table($name, $rows), array_keys($data), $data));
        }

        throw new ImportException('Le JSON doit être une liste d\'objets, ou un objet dont chaque clé contient une liste d\'objets.');
    }

    /**
     * @param  list<mixed>  $records
     */
    private function table(string $name, array $records): Table
    {
        $columns = [];

        foreach ($records as $index => $record) {
            if (! is_array($record) || array_is_list($record)) {
                throw new ImportException("{$name} : l'élément n°".($index + 1).' n\'est pas un objet.');
            }

            foreach (array_keys($record) as $key) {
                $columns[(string) $key] = true;
            }
        }

        $names = array_keys($columns);

        $rows = array_map(fn (array $record) => array_map(function (string $column) use ($record) {
            $value = $record[$column] ?? null;

            return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value;
        }, $names), $records);

        return new Table($name, array_map(fn (string $column) => new Column($column), $names), $rows);
    }
}
