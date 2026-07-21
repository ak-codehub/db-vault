<?php

declare(strict_types=1);

namespace DbVault\Tests;

use DbVault\DbVaultServiceProvider;
use DbVault\Models\Role;
use DbVault\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the db-vault package. Boots the package service provider
 * against a Testbench host app on an in-memory SQLite database (the package's
 * own migrations are loaded by the provider and run by RefreshDatabase).
 *
 * The mounted route group is configured with a session-enabled but
 * CSRF-free middleware stack for tests, so cookie-session auth (login, the
 * two-factor challenge, actingAs) works without every POST needing a token.
 */
abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    /**
     * @param  \Illuminate\Foundation\Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DbVaultServiceProvider::class];
    }

    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('dbvault.middleware', [
            EncryptCookies::class,
            StartSession::class,
            SubstituteBindings::class,
        ]);
        $app['config']->set('dbvault.pma_signon_url', 'https://pma.test/signon.php');
        $app['config']->set('dbvault.server_label', 'test');
    }

    /**
     * Create an active vault user holding the given role.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function makeUser(string $role = 'developer', array $attributes = []): User
    {
        foreach (['developer', 'approver', 'admin', 'auditor'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }

        $user = User::create(array_merge([
            'name' => ucfirst($role).' User',
            'email' => $role.'-'.uniqid().'@dbvault.test',
            'password' => 'password',
            'is_active' => true,
        ], $attributes));

        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }
}
