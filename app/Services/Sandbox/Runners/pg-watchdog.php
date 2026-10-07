<?php

/*
|--------------------------------------------------------------------------
| Chien de garde PostgreSQL
|--------------------------------------------------------------------------
|
| Lancé par App\Services\Sandbox\Drivers\PostgresDriver pendant l'exécution de code
| stocké (fonctions, procédures, triggers). statement_timeout envoie une annulation
| que PL/pgSQL peut intercepter ; ce processus, lui, met fin à la connexion
| (pg_terminate_backend, non interceptable) si elle dépasse son délai.
| Le parent l'arrête dès que la requête se termine.
|
| Entrée (variable d'environnement SANDBOX_WATCHDOG, JSON) : dsn, username, password, pid, delay_ms
| Sortie : « terminated » si la connexion a été coupée.
|
*/

$input = json_decode((string) getenv('SANDBOX_WATCHDOG'), true);

usleep(max(0, (int) $input['delay_ms']) * 1000);

try {
    $pdo = new PDO($input['dsn'], $input['username'], $input['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('SET ROLE pg_signal_backend'); // compte NOINHERIT : le droit doit être endossé
    $statement = $pdo->prepare('SELECT pg_terminate_backend(?)');
    $statement->execute([(int) $input['pid']]);

    if ($statement->fetchColumn()) {
        echo 'terminated';
    }
} catch (PDOException $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
