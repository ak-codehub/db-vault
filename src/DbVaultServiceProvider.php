<?php

declare(strict_types=1);

namespace DbVault;

use DbVault\Console\Commands\AdminCommand;
use DbVault\Console\Commands\DropExpiredDbSessions;
use DbVault\Console\Commands\InstallCommand;
use DbVault\Console\Commands\InstallPhpMyAdmin;
use DbVault\Console\Commands\MakeCertificateAuthority;
use DbVault\Console\Commands\PhpMyAdminVhost;
use DbVault\Http\Middleware\Authenticate;
use DbVault\Http\Middleware\EnsureRole;
use DbVault\Http\Middleware\EnsureViewDbVaultGate;
use DbVault\Http\Middleware\ForceJsonResponse;
use DbVault\Http\Middleware\TrustClientCertificate;
use DbVault\Models\AccessRequest;
use DbVault\Models\User;
use DbVault\Policies\AccessRequestPolicy;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Routing\Router;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PragmaRX\Google2FA\Google2FA;

/**
 * The db-vault package's entry point. Wires the self-contained panel into a
 * host Laravel 12 application without the host having to know anything about
 * the vault's internals:
 *
 *  - merges the package's own config (config/dbvault.php);
 *  - registers a dedicated `vault` auth guard + Eloquent user provider so the
 *    vault's users never collide with the host app's own users/guards;
 *  - aliases the package middleware (vault.auth / vault.role / vault.gate /
 *    vault.mtls);
 *  - defines the `viewDbVault` authorization gate and the AccessRequest policy;
 *  - loads the package migrations and the SPA boot view;
 *  - mounts the JSON API and the SPA at the configured domain/path
 *    (config('dbvault.domain') / config('dbvault.path'));
 *  - exposes publishable config + compiled front-end assets, and the package's
 *    console commands.
 *
 * @see config/dbvault.php for every knob referenced here.
 */
class DbVaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dbvault.php', 'dbvault');

        $this->registerDatabaseConnection();
        $this->registerAuthGuard();

        // pragmarx/google2fa is stateless; a single shared instance backs
        // DbVault\Services\TwoFactorService for TOTP verification/enrolment.
        $this->app->singleton(Google2FA::class, static fn () => new Google2FA());
    }

    public function boot(Router $router): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'db-vault');

        $this->registerMiddlewareAliases($router);
        $this->registerAuthorization();
        $this->registerRoutes();
        $this->registerExceptionRendering();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();

            $this->commands([
                InstallCommand::class,
                AdminCommand::class,
                DropExpiredDbSessions::class,
                MakeCertificateAuthority::class,
                InstallPhpMyAdmin::class,
                PhpMyAdminVhost::class,
            ]);
        }
    }

    /**
     * Ensure validation/auth failures on the vault's API return JSON, not an
     * HTML redirect — independent of the host app's exception-handler config.
     *
     * A host may scope JSON rendering to its own URLs (e.g.
     * `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))`), which does not
     * match the vault's "{path}/api/*" mount, so its validation errors would
     * 302-redirect and the SPA could not read the field messages. Registering
     * a renderable on the container's handler wins regardless.
     */
    protected function registerExceptionRendering(): void
    {
        $handler = $this->app->make(ExceptionHandler::class);

        $path = trim((string) config('dbvault.path', 'vault'), '/');
        $apiPrefix = ($path === '' ? '' : $path.'/').'api/*';

        $onVaultApi = static function ($request) use ($apiPrefix): bool {
            return $request->is($apiPrefix);
        };

        $handler->renderable(static function (ValidationException $e, $request) use ($onVaultApi) {
            if ($onVaultApi($request)) {
                return response()->json(
                    ['message' => $e->getMessage(), 'errors' => $e->errors()],
                    $e->status,
                );
            }

            return null;
        });

        $handler->renderable(static function (AuthenticationException $e, $request) use ($onVaultApi) {
            if ($onVaultApi($request)) {
                return response()->json(['message' => $e->getMessage()], 401);
            }

            return null;
        });
    }

    /**
     * Make the vault's dedicated storage connection available to the host
     * application's database manager, so the vault's models and migrations
     * can bind to it without the host having to edit its own
     * config/database.php. Additive only: any connection the package defines
     * that the host has NOT already declared is copied in; existing host
     * connections are never overwritten.
     */
    protected function registerDatabaseConnection(): void
    {
        // Only build the dedicated connection when the vault actually uses it
        // (config('dbvault.connection') === 'dbvault'); otherwise the vault
        // rides the host's default connection and there is nothing to register.
        if (config('dbvault.connection') !== 'dbvault') {
            return;
        }

        // Don't clobber a 'dbvault' connection the host may have defined itself.
        if (config('database.connections.dbvault')) {
            return;
        }

        $overrides = array_filter(
            (array) config('dbvault.connections.dbvault', []),
            static fn ($v) => $v !== null && $v !== '',
        );

        $driver = $overrides['driver']
            ?? config('database.connections.'.config('database.default').'.driver')
            ?? 'mysql';

        if ($driver === 'sqlite') {
            // SQLite: inherit the host's sqlite config, override only the file.
            $base = (array) config('database.connections.sqlite', [
                'driver' => 'sqlite', 'prefix' => '', 'foreign_key_constraints' => true,
            ]);
            $base['driver'] = 'sqlite';
            if (isset($overrides['path'])) {
                $base['database'] = $overrides['path'];
            } elseif (isset($overrides['database'])) {
                $base['database'] = $overrides['database'];
            }
            config(['database.connections.dbvault' => $base]);

            return;
        }

        // MySQL / MariaDB / Postgres: START from the host's DEFAULT connection
        // (so username/password/host/charset that already work are reused),
        // then apply only the explicitly-set DBVAULT_DB_* overrides + the
        // target database name.
        $base = (array) config('database.connections.'.config('database.default'), []);
        $base['driver'] = $driver;

        foreach (['host', 'port', 'username', 'password', 'unix_socket'] as $key) {
            if (isset($overrides[$key])) {
                $base[$key] = $overrides[$key];
            }
        }
        if (isset($overrides['database'])) {
            $base['database'] = $overrides['database'];
        }

        config(['database.connections.dbvault' => $base]);
    }

    /**
     * Register the vault's own session guard and Eloquent user provider.
     *
     * This runs in register() (after the config merge) so the guard exists
     * before any route/middleware resolves a user. It is intentionally
     * additive: it never touches the host application's existing guards or
     * providers, so a vault session and a host-app session are fully
     * independent.
     */
    protected function registerAuthGuard(): void
    {
        $guard = (string) config('dbvault.guard', 'vault');

        config([
            "auth.guards.{$guard}" => [
                'driver' => 'session',
                'provider' => 'dbvault_users',
            ],
            'auth.providers.dbvault_users' => [
                'driver' => 'eloquent',
                'model' => User::class,
            ],
        ]);
    }

    /**
     * Expose the package middleware under short route aliases. Operators can
     * additionally prepend 'vault.mtls' to config('dbvault.middleware') in an
     * environment where the reverse proxy actually terminates mTLS.
     */
    protected function registerMiddlewareAliases(Router $router): void
    {
        $router->aliasMiddleware('vault.auth', Authenticate::class);
        $router->aliasMiddleware('vault.role', EnsureRole::class);
        $router->aliasMiddleware('vault.gate', EnsureViewDbVaultGate::class);
        $router->aliasMiddleware('vault.mtls', TrustClientCertificate::class);
    }

    /**
     * Define the panel-wide `viewDbVault` ability and bind the AccessRequest
     * policy. Host applications may override the gate to layer on extra
     * restrictions (IP allowlists, feature flags, ...).
     */
    protected function registerAuthorization(): void
    {
        Gate::define('viewDbVault', static function (?Authenticatable $user): bool {
            return $user instanceof User
                && $user->is_active
                && ($user->hasRole('developer') || $user->hasRole('approver') || $user->hasRole('admin'));
        });

        Gate::policy(AccessRequest::class, AccessRequestPolicy::class);
    }

    /**
     * Mount the JSON API and the SPA at the configured domain/path.
     *
     * The API group is registered first and, being more specific
     * ("{path}/api/..."), always wins over the SPA catch-all
     * ("{path}/{any}") for API URLs.
     */
    protected function registerRoutes(): void
    {
        $domain = config('dbvault.domain');
        $path = trim((string) config('dbvault.path', 'vault'), '/');
        $middleware = (array) config('dbvault.middleware', ['web']);

        Route::group([
            'domain' => $domain,
            'prefix' => $path === '' ? 'api' : $path.'/api',
            // ForceJsonResponse guarantees the API always returns JSON (422
            // for validation, 401 for auth) rather than an HTML redirect,
            // independent of the host app's exception-handler config.
            'middleware' => array_merge($middleware, [ForceJsonResponse::class]),
            'as' => 'db-vault.api.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        });

        // Server-to-server signon token exchange (phpMyAdmin → vault). This
        // is stateless and guarded by a shared-secret header, so it runs
        // OUTSIDE the host 'web' group — no session, no CSRF — which is why
        // it is registered here rather than in the web-grouped api.php.
        Route::group([
            'domain' => $domain,
            'prefix' => $path === '' ? 'api' : $path.'/api',
            'middleware' => [ForceJsonResponse::class],
            'as' => 'db-vault.api.',
        ], function (): void {
            Route::post('sessions/exchange', [\DbVault\Http\Controllers\Api\DbSessionController::class, 'exchange'])
                ->name('sessions.exchange');
        });

        // phpMyAdmin is NOT routed through Laravel: it ships with its own
        // Composer vendor tree (psr/log, thecodingmachine/safe, …) that cannot
        // coexist in the booted Laravel process. Instead it is served by the
        // web server directly under "{path}/pma" via the packaged nginx
        // snippet (see phpmyadmin/deploy/nginx-pma.conf), on the SAME
        // origin/TLS as the panel — no separate port. Only the signon token
        // exchange crosses back into Laravel (routes above).

        Route::group([
            'domain' => $domain,
            'prefix' => $path,
            'middleware' => $middleware,
            'as' => 'db-vault.',
        ], function (): void {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        });
    }

    /**
     * Declare what `php artisan vendor:publish` can copy into the host app:
     * the config file and the compiled SPA assets (served from
     * public/vendor/db-vault by the SPA boot view).
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            __DIR__.'/../config/dbvault.php' => $this->app->configPath('dbvault.php'),
        ], 'db-vault-config');

        $this->publishes([
            __DIR__.'/../public' => $this->app->publicPath('vendor/db-vault'),
        ], 'db-vault-assets');
    }
}
