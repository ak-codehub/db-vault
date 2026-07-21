<?php

declare(strict_types=1);

namespace DbVault\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * `vault.gate`: the final authorization gate for the whole panel. Checks
 * the `viewDbVault` ability defined in DbVaultServiceProvider::boot(),
 * which by default requires an authenticated vault user holding any vault
 * role. Host applications may override the gate definition to layer on
 * additional restrictions (IP allowlists, feature flags, ...).
 */
class EnsureViewDbVaultGate
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Gate::denies('viewDbVault')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
