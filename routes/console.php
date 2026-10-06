<?php

use App\Services\Gamification\LeaderboardService;
use App\Services\Sandbox\Drivers\PostgresDriver;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sandbox:setup-pgsql {--superuser=postgres : Superutilisateur PostgreSQL utilisé pour créer les comptes}', function () {
    $config = config('sandbox.drivers.pgsql');

    if (blank($config['owner_password']) || blank($config['runner_password'])) {
        $this->error('Définissez SANDBOX_PGSQL_OWNER_PASSWORD et SANDBOX_PGSQL_RUNNER_PASSWORD avant de lancer cette commande.');

        return 1;
    }

    $password = $config['superuser_password'] ?? $this->secret("Mot de passe de {$this->option('superuser')}");

    (new PostgresDriver($config))->installRoles($this->option('superuser'), (string) $password);

    $this->info("Comptes « {$config['owner_username']} » (chargement) et « {$config['runner_username']} » (exécution) prêts sur {$config['database']}.");
})->purpose('Crée les comptes PostgreSQL sans privilèges de la sandbox');

Artisan::command('leaderboard:snapshot', function (LeaderboardService $leaderboard) {
    $this->info($leaderboard->snapshot().' positions figées.');
})->purpose('Fige les classements du jour (progression ▲ ▼ et historique)');

Schedule::command('leaderboard:snapshot')->dailyAt('00:05');
