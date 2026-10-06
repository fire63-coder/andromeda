<?php

namespace App\Services\Sandbox\Contracts;

use App\Models\DatasetBuild;
use App\Services\Sandbox\GuardedQuery;
use App\Services\Sandbox\QueryResult;

interface SandboxDriver
{
    /**
     * Matérialise le build (base modèle, schéma...) s'il ne l'est pas déjà.
     * Idempotent. Retourne le nom de la ressource créée.
     */
    public function prepare(DatasetBuild $build): string;

    /**
     * Exécute la requête sur une copie isolée du build. Les modifications
     * ne sont JAMAIS persistées.
     *
     * @param  list<string>  $checkQueries  requêtes SELECT exécutées après la requête (exercices DML/DDL)
     */
    public function execute(
        DatasetBuild $build,
        GuardedQuery $query,
        int $timeoutMs,
        int $maxRows,
        array $checkQueries = [],
    ): QueryResult;

    /**
     * Le moteur peut-il exécuter du DDL de façon annulable ?
     */
    public function supportsDdl(): bool;

    /**
     * Supprime la ressource matérialisée du build.
     */
    public function destroy(DatasetBuild $build): void;
}
