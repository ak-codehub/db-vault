<?php

declare(strict_types=1);

namespace DbVault\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `vault.role`: simple RBAC gate. Usage in routes:
 * ->middleware('vault.role:approver') or ->middleware('vault.role:admin,approver')
 * to allow any of several roles.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = Auth::guard(config('dbvault.guard', 'vault'))->user();

        if (! $user || ! $user->is_active) {
            return response()->json(['message' => 'Your account is not active.'], 403);
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'You do not have permission to perform this action.'], 403);
    }
}
