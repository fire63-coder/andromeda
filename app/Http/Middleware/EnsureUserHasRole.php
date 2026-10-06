<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage : ->middleware('role:admin,trainer')
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $role = $request->user()?->role;

        abort_unless($role && in_array($role, array_map(fn (string $r) => UserRole::from($r), $roles), true), 403);

        return $next($request);
    }
}
