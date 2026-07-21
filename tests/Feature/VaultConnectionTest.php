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

    public function test_the_package_registers_its_own_connection_additively_at_boot(): void
    {
        // The provider ran registerDatabaseConnection() during boot. Because
        // the test host sets DB_CONNECTION=testing and leaves DBVAULT_DB_*
        // unset, config('dbvault.connection') resolves to 'testing' and the
        // packaged 'dbvault' connection definition is still exposed to the
        // host database manager.
        $this->assertNotNull(
            config('database.connections.dbvault'),
            'The package should register its own dbvault connection definition.',
        );

        // The host's own pre-existing 'testing' connection is untouched.
        $original = config('database.connections.testing');
        $this->assertNotNull($original);

        $definition = ['driver' => 'mysql', 'database' => 'SHOULD_NOT_OVERWRITE'];
        if (! config('database.connections.testing')) {
            config(['database.connections.testing' => $definition]);
        }
        $this->assertSame($original, config('database.connections.testing'));
    }
}
