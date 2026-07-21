<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Console\Commands\InstallCommand;
use DbVault\Models\Role;
use DbVault\Models\User;
use DbVault\Tests\TestCase;
use ReflectionMethod;

class InstallCommandTest extends TestCase
{
    private function invoke(string $method, mixed ...$args): mixed
    {
        $command = $this->app->make(InstallCommand::class);
        $ref = new ReflectionMethod($command, $method);
        $ref->setAccessible(true);

        return $ref->invoke($command, ...$args);
    }

    public function test_only_plain_identifiers_are_accepted_as_database_names(): void
    {
        foreach (['dbvault', 'vault_db', 'DB123', 'a'] as $ok) {
            $this->assertTrue($this->invoke('isSafeIdentifier', $ok), "{$ok} should be safe");
        }

        // Anything that could break out of the CREATE DATABASE identifier must
        // be rejected — this guards the one place a name is interpolated.
        foreach ([
            '',
            'vault; DROP DATABASE app',
            'vault`; DROP',
            'vault db',
            'vault-db',
            'vault"x',
            "vault'x",
            'vault.db',
        ] as $bad) {
            $this->assertFalse($this->invoke('isSafeIdentifier', $bad), "{$bad} must be rejected");
        }
    }

    public function test_charset_and_collation_tokens_are_whitelisted(): void
    {
        $this->assertSame('utf8mb4', $this->invoke('safeToken', 'utf8mb4', 'fallback'));
        $this->assertSame('utf8mb4_unicode_ci', $this->invoke('safeToken', 'utf8mb4_unicode_ci', 'fallback'));

        // Malicious / malformed values fall back to the safe default instead of
        // being interpolated into DDL.
        $this->assertSame('fallback', $this->invoke('safeToken', 'utf8; DROP', 'fallback'));
        $this->assertSame('fallback', $this->invoke('safeToken', 'a b', 'fallback'));
        $this->assertSame('fallback', $this->invoke('safeToken', '', 'fallback'));
    }

    public function test_quote_identifier_wraps_per_driver(): void
    {
        $this->assertSame('`vault_db`', $this->invoke('quoteIdentifier', 'vault_db', 'mysql'));
        $this->assertSame('"vault_db"', $this->invoke('quoteIdentifier', 'vault_db', 'pgsql'));
    }

    public function test_sqlite_needs_no_database_creation_step(): void
    {
        // The default test connection is in-memory sqlite; ensureVaultDatabaseExists
        // must short-circuit to true (the file/memory DB always "exists").
        $this->assertTrue($this->invoke('ensureVaultDatabaseExists'));
    }

    public function test_full_install_produces_working_schema_roles_and_admin(): void
    {
        config()->set('dbvault.connection', config('database.default'));

        putenv('DBVAULT_ADMIN_NAME=Install Admin');
        putenv('DBVAULT_ADMIN_EMAIL=install-admin@dbvault.test');
        putenv('DBVAULT_ADMIN_PASSWORD=install-password');

        try {
            $this->artisan('db-vault:install', ['--no-interaction' => true])
                ->assertExitCode(0);
        } finally {
            putenv('DBVAULT_ADMIN_NAME');
            putenv('DBVAULT_ADMIN_EMAIL');
            putenv('DBVAULT_ADMIN_PASSWORD');
        }

        // RBAC roles seeded.
        foreach (['developer', 'approver', 'admin', 'auditor'] as $role) {
            $this->assertTrue(Role::where('name', $role)->exists(), "role {$role} seeded");
        }

        // First admin created and granted the admin role.
        $admin = User::where('email', 'install-admin@dbvault.test')->first();
        $this->assertNotNull($admin, 'admin user created');
        $this->assertTrue(
            $admin->roles()->where('name', 'admin')->exists(),
            'admin user holds the admin role'
        );
    }

    public function test_missing_database_error_is_recognised(): void
    {
        $mysql = new \RuntimeException("SQLSTATE[HY000] [1049] Unknown database 'nope'");
        $pgsql = new \RuntimeException('database "nope" does not exist');
        $creds = new \RuntimeException('SQLSTATE[HY000] [1045] Access denied for user');

        $this->assertTrue($this->invoke('isUnknownDatabaseError', $mysql));
        $this->assertTrue($this->invoke('isUnknownDatabaseError', $pgsql));
        $this->assertFalse($this->invoke('isUnknownDatabaseError', $creds));
    }
}
