<?php

namespace App\Services\Sandbox;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Lance Runners/sqlite-runner.php dans un processus PHP isolé :
 * open_basedir limité au seul fichier de base, mémoire bornée,
 * fonctions système désactivées, processus tué au-delà du délai.
 */
class SqliteProcess
{
    /**
     * @param  array{php_binary: string, memory_limit: string}  $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self(config('sandbox.drivers.sqlite'));
    }

    /**
     * Construit une base à partir d'un script (déjà validé par QueryGuard::inspectScript()).
     */
    public function build(string $database, string $script, int $timeoutMs = 120_000): QueryResult
    {
        // Créé vide ici : open_basedir, limité à ce fichier, interdit au processus isolé de le créer.
        touch($database);

        return $this->run(['mode' => 'build', 'database' => $database, 'script' => $script], $timeoutMs);
    }

    /**
     * @param  array<string, mixed>  $payload  voir l'en-tête de sqlite-runner.php
     */
    public function run(array $payload, int $timeoutMs): QueryResult
    {
        $runner = __DIR__.'/Runners/sqlite-runner.php';

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
