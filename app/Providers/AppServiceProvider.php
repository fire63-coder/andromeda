<?php

namespace App\Providers;

use App\Services\Sandbox\SandboxManager;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Garde les moteurs (et leurs schémas déjà provisionnés) en mémoire pendant la requête.
        $this->app->singleton(SandboxManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
