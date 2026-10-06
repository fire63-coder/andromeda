<?php

namespace App\Livewire\Concerns;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Limite le nombre d'exécutions sandbox par utilisateur (config sandbox.rate_limit_per_minute).
 */
trait ThrottlesSandbox
{
    /**
     * Compte une exécution. Retourne le message à afficher si la limite est atteinte, sinon null.
     */
    protected function sandboxThrottled(): ?string
    {
        $key = 'sandbox:'.auth()->id();

        if (RateLimiter::tooManyAttempts($key, (int) config('sandbox.rate_limit_per_minute'))) {
            return "Trop d'exécutions rapprochées : réessayez dans ".RateLimiter::availableIn($key).' s.';
        }

        RateLimiter::hit($key, 60);

        return null;
    }
}
