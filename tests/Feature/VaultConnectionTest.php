<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Models\AccessRequest;
use DbVault\Models\Role;
use DbVault\Models\User;
use DbVault\Tests\TestCase;

class VaultConnectionTest extends TestCase
{
    public function test_vault_models_bind_to_the_configured_vault_connection(): void
    {
        config()->set('dbvault.connection', 'some_vault_conn');

        $this->assertSame('some_vault_conn', (new User())->getConnectionName());
        $this->assertSame('some_vault_conn', (new Role())->getConnectionName());
        $this->assertSame('some_vault_conn', (new AccessRequest())->getConnectionName());
    }

    public function test_no_dedicated_connection_is_registered_when_not_configured(): void
    {
        // With DBVAULT_DB_* unset, the vault rides the host default connection
        // and no separate 'dbvault' connection is built. (Regression guard:
        // the provider must NOT invent a connection with default/empty creds.)
        $this->assertNotSame('dbvault', config('dbvault.connection'));
        $this->assertNull(config('database.connections.dbvault'));
    }

    public function test_dedicated_connection_inherits_host_credentials_and_overrides_only_the_database(): void
    {
        // Regression guard for the install failure: setting just the vault DB
        // name must reuse the host's WORKING driver/host/user/password and
        // only swap the database — not fall back to empty creds.
        //
        // We assert on the built config array only (never open the connection,
        // so no live DB is needed) and restore state afterwards.
        $realDefault = config('database.default');

        try {
            config()->set('database.default', 'mysqlish');
            config()->set('database.connections.mysqlish', [
                'driver' => 'mysql', 'host' => 'db.example', 'port' => '3307',
                'database' => 'app_db', 'username' => 'appuser', 'password' => 's3cret',
                'charset' => 'utf8mb4',
            ]);
            config()->set('dbvault.connection', 'dbvault');
            config()->set('dbvault.connections.dbvault', [
                'driver' => null, 'database' => 'vault_db', 'path' => null,
                'host' => null, 'port' => null, 'username' => null,
                'password' => null, 'unix_socket' => null,
            ]);
            config()->set('database.connections.dbvault', null);

            $provider = new \DbVault\DbVaultServiceProvider($this->app);
            $m = new \ReflectionMethod($provider, 'registerDatabaseConnection');
            $m->setAccessible(true);
            $m->invoke($provider);

            $c = config('database.connections.dbvault');
            $this->assertSame('vault_db', $c['database'], 'database is overridden');
            $this->assertSame('appuser', $c['username'], 'username inherited from host default');
            $this->assertSame('s3cret', $c['password'], 'password inherited from host default');
            $this->assertSame('db.example', $c['host'], 'host inherited from host default');
        } finally {
            // Restore so RefreshDatabase teardown uses the real test connection.
            config()->set('database.default', $realDefault);
            config()->set('database.connections.dbvault', null);
        }
    }
}
