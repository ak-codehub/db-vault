<?php

declare(strict_types=1);

namespace DbVault\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `vault.auth`: requires an authenticated session on the vault's own guard
 * (config('dbvault.guard'), default 'vault'). Distinct from the host
 * application's own 'auth' middleware/guard - a vault session never implies
 * a host-app session or vice versa. Always responds with JSON (this route
 * group is a JSON API with no server-rendered fallback), so the SPA can
 * treat any 401 uniformly.
 */
class Authenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $guard = config('dbvault.guard', 'vault');

        if (! Auth::guard($guard)->check()) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        Auth::shouldUse($guard);

        return $next($request);
    }
}
