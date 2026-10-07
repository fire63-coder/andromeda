<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Déconnecte immédiatement un utilisateur désactivé pendant sa session.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            Auth::forgetGuards(); // le garde sanctum mémorise l'utilisateur résolu via la session

            return redirect()->route('login')->withErrors(['email' => 'Ce compte a été désactivé. Contactez un administrateur.']);
        }

        return $next($request);
    }
}
