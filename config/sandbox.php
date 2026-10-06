<?php

use App\Services\Sandbox\Drivers\MysqlDriver;
use App\Services\Sandbox\Drivers\PostgresDriver;
use App\Services\Sandbox\Drivers\SqliteDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Limites communes
    |--------------------------------------------------------------------------
    |
    | Appliquées à toute requête d'élève, quel que soit le moteur. La limite
    | de temps propre à un exercice (exercises.max_execution_ms) ne peut pas
    | dépasser "max_execution_ms".
    |
    */

    'max_execution_ms' => (int) env('SANDBOX_MAX_EXECUTION_MS', 5000),

    'max_rows' => (int) env('SANDBOX_MAX_ROWS', 500),

    'max_query_length' => (int) env('SANDBOX_MAX_QUERY_LENGTH', 10000),

    // Nombre d'exécutions ("Exécuter" + "Valider") autorisées par minute et par utilisateur.
    'rate_limit_per_minute' => (int) env('SANDBOX_RATE_LIMIT', 30),

    /*
    |--------------------------------------------------------------------------
    | Moteurs
    |--------------------------------------------------------------------------
    |
    | Clé = sql_dialects.slug. Un dialecte n'est exécutable que s'il est
    | déclaré ici ET marqué is_sandbox_enabled en base.
    |
    */

    'drivers' => [

        'sqlite' => [
            'driver' => SqliteDriver::class,
            // Bases modèles (une par build de jeu de données) et copies temporaires.
            'path' => env('SANDBOX_SQLITE_PATH', storage_path('app/private/sandbox/sqlite')),
            // Binaire PHP utilisé pour lancer le processus isolé d'exécution.
            'php_binary' => env('SANDBOX_PHP_BINARY', PHP_BINARY),
            'memory_limit' => env('SANDBOX_SQLITE_MEMORY_LIMIT', '128M'),
        ],

        'pgsql' => [
            'driver' => PostgresDriver::class,
            // Serveur DÉDIÉ aux sandboxes, jamais la base applicative.
            'host' => env('SANDBOX_PGSQL_HOST', '127.0.0.1'),
            'port' => env('SANDBOX_PGSQL_PORT', '5432'),
            'database' => env('SANDBOX_PGSQL_DATABASE', 'andromeda_sandbox'),
            // Compte SANS privilèges serveur qui crée les schémas et charge les jeux de données
            // (leurs scripts peuvent venir d'un import : jamais avec un superutilisateur).
            'owner_username' => env('SANDBOX_PGSQL_OWNER_USERNAME', 'andromeda_owner'),
            'owner_password' => env('SANDBOX_PGSQL_OWNER_PASSWORD', ''),
            // Compte SANS privilèges qui exécute les requêtes des élèves.
            // Les deux comptes sont créés par `php artisan sandbox:setup-pgsql` (avec un superutilisateur).
            'runner_username' => env('SANDBOX_PGSQL_RUNNER_USERNAME', 'andromeda_runner'),
            'runner_password' => env('SANDBOX_PGSQL_RUNNER_PASSWORD', ''),
            'lock_timeout_ms' => (int) env('SANDBOX_PGSQL_LOCK_TIMEOUT_MS', 1000),
            // Utilisé uniquement par `sandbox:setup-pgsql` (sinon le mot de passe est demandé).
            'superuser_password' => env('SANDBOX_PGSQL_SUPERUSER_PASSWORD'),
        ],

        'mysql' => [
            'driver' => MysqlDriver::class,
            // Serveur DÉDIÉ aux sandboxes : une base « sbx_… » par build de jeu de données.
            'host' => env('SANDBOX_MYSQL_HOST', '127.0.0.1'),
            'port' => (int) env('SANDBOX_MYSQL_PORT', 3306),
            'database_prefix' => 'sbx_',
            // Comptes créés par `php artisan sandbox:setup-mysql` (avec un superutilisateur).
            'owner_username' => env('SANDBOX_MYSQL_OWNER_USERNAME', 'andromeda_owner'),
            'owner_password' => env('SANDBOX_MYSQL_OWNER_PASSWORD', ''),
            'runner_username' => env('SANDBOX_MYSQL_RUNNER_USERNAME', 'andromeda_runner'),
            'runner_password' => env('SANDBOX_MYSQL_RUNNER_PASSWORD', ''),
            'lock_timeout_s' => (int) env('SANDBOX_MYSQL_LOCK_TIMEOUT_S', 1),
            'superuser_password' => env('SANDBOX_MYSQL_SUPERUSER_PASSWORD'),
        ],

    ],

];
