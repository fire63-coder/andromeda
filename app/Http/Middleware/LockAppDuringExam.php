<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pendant une épreuve en mode examen, le reste de l'application (cours, exercices, arène…) est fermé :
 * toute page ouverte ramène à l'épreuve.
 */
class LockAppDuringExam
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') || $request->routeIs('certifications.attempt')) {
            return $next($request);
        }

        $exam = $request->user()?->activeSecureExam();

        if ($exam) {
            return redirect()->route('certifications.attempt', $exam)
                ->with('flash.banner', 'Épreuve en mode examen en cours : le reste de l\'application est fermé jusqu\'à la fin.')
                ->with('flash.bannerStyle', 'danger');
        }

        return $next($request);
    }
}
