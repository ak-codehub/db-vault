<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

/**
 * Base controller for the vault's own JSON API. Provides authorizeVault(),
 * a policy-check helper bound to the vault guard's user rather than the
 * host application's default guard, since Illuminate\Foundation\Auth\Access\AuthorizesRequests::authorize()
 * resolves the "current user" from the default guard.
 */
abstract class Controller extends BaseController
{
    /**
     * @param  \Illuminate\Database\Eloquent\Model|string|array  $arguments
     *
     * @throws AuthorizationException
     */
    protected function authorizeVault(string $ability, mixed $arguments = []): void
    {
        Gate::forUser(Auth::guard(config('dbvault.guard', 'vault'))->user())
            ->authorize($ability, $arguments);
    }
}
