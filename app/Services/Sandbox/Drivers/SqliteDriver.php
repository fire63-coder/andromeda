<?php

namespace App\Services\Sandbox\Drivers;

use App\Models\DatasetBuild;
use App\Services\Sandbox\Contracts\SandboxDriver;
use App\Services\Sandbox\Exceptions\SandboxUnavailable;
use App\Services\Sandbox\GuardedQuery;
use App\Services\Sandbox\QueryResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Une base SQLite "modèle" par build de jeu de données.
 *
 * - Requêtes en lecture seule : le modèle est ouvert en SQLITE_OPEN_READONLY.
 * - Requêtes qui modifient : exécutées sur une copie jetable, supprimée ensuite.
 *
 * L'exécution a lieu dans un processus PHP séparé (Runners/sqlite-runner.php)
 * que l'on tue au-delà du temps limite : SQLite n'a pas de timeout par requête.
 */
class SqliteDriver implements SandboxDriver
{
    private const BUILD_TIMEOUT_MS = 120_000;

    /**
     * @param  array{path: string, php_binary: string, memory_limit: string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function prepare(DatasetBuild $build): string
    {
        $template = $this->templatePath($build);

        if (is_file($template)) {
            return basename($template);
        }

        File::ensureDirectoryExists(dirname($template));
        $lock = fopen($template.'.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            if (! is_file($template)) {
                $this->buildTemplate($build, $template);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($template.'.lock');
        }

        return basename($template);
    }

    public function execute(
        DatasetBuild $build,
        GuardedQuery $query,
        int $timeoutMs,
        int $maxRows,
        array $checkQueries = [],
    ): QueryResult {
        $template = $this->config['path'].'/templates/'.$this->prepare($build);
        $readOnly = $query->isReadOnly() && $checkQueries === [];
        $database = $template;

        if (! $readOnly) {
            $database = $this->config['path'].'/runs/'.Str::uuid().'.sqlite';
            File::ensureDirectoryExists(dirname($database));
            copy($template, $database);
        }

        try {
            return $this->runInProcess([
                'database' => $database,
                'readonly' => $readOnly,
                'statements' => $query->statements,
                'checks' => (object) $checkQueries,
                'max_rows' => $maxRows,
            ], $timeoutMs);
        } finally {
            if (! $readOnly) {
                @unlink($database);
            }
        }
    }

    public function destroy(DatasetBuild $build): void
    {
        foreach (glob($this->config['path']."/templates/ds{$build->dataset_id}_b{$build->id}_*.sqlite") ?: [] as $file) {
            @unlink($file);
        }
    }

    private function templatePath(DatasetBuild $build): string
    {
        // Le hash change dès que le build est reconstruit : l'ancien modèle n'est plus utilisé.
        $version = substr(md5($build->updated_at?->toIso8601String().$build->schema_sql), 0, 10);

        return $this->config['path']."/templates/ds{$build->dataset_id}_b{$build->id}_{$version}.sqlite";
    }

    private function buildTemplate(DatasetBuild $build, string $template): void
    {
        $temporary = $template.'.'.Str::random(8).'.tmp';
        // Créé vide ici : open_basedir, limité à ce fichier, interdit au processus isolé de le créer.
        touch($temporary);

        // Le script vient d'un import : il est validé puis exécuté dans le processus isolé,
        // jamais dans le processus de l'application.
        $result = $this->runInProcess([
            'mode' => 'build',
            'database' => $temporary,
            'script' => $build->validatedScript(),
        ], self::BUILD_TIMEOUT_MS);

        if (! $result->success) {
            @unlink($temporary);

            throw new SandboxUnavailable("Impossible de construire la base SQLite du jeu de données : {$result->error}");
        }

        rename($temporary, $template);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function runInProcess(array $payload, int $timeoutMs): QueryResult
    {
        $runner = dirname(__DIR__).'/Runners/sqlite-runner.php';

        $process = new Process([
            $this->config['php_binary'],
            '-d', 'memory_limit='.$this->config['memory_limit'],
            // Limité au seul fichier de la base (et à son journal) : un ATTACH vers une autre base est refusé.
            '-d', 'open_basedir='.$payload['database'].PATH_SEPARATOR.dirname($runner),
            '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,curl_exec,mail,putenv',
            '-d', 'display_errors=stderr',
            $runner,
        ]);

        // Démarrer un processus PHP coûte quelques dizaines de ms : marge fixe en plus du temps alloué.
        $process->setTimeout(($timeoutMs + 1000) / 1000);
        $process->setInput(json_encode($payload));

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return QueryResult::failure($this->timeoutMessage($timeoutMs), QueryResult::ERROR_TIMEOUT, $timeoutMs);
        }

        $decoded = json_decode($process->getOutput(), true);

        if (! is_array($decoded)) {
            report(new \RuntimeException('Sandbox SQLite : sortie invalide. '.$process->getErrorOutput()));

            return QueryResult::failure(
                str_contains($process->getErrorOutput(), 'memory size')
                    ? 'La requête consomme trop de mémoire.'
                    : 'Erreur interne du bac à sable.',
                QueryResult::ERROR_INTERNAL,
            );
        }

        $result = QueryResult::fromArray($decoded);

        if ($result->success && $result->durationMs > $timeoutMs) {
            return QueryResult::failure($this->timeoutMessage($timeoutMs), QueryResult::ERROR_TIMEOUT, $result->durationMs);
        }

        return $result;
    }

    private function timeoutMessage(int $timeoutMs): string
    {
        return "La requête a dépassé le temps limite de {$timeoutMs} ms.";
    }
}
