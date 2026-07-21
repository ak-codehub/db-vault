<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Models\Role;
use DbVault\Models\User;
use DbVault\Tests\TestCase;

class AdminCommandTest extends TestCase
{
    private function seedRoles(): void
    {
        foreach (['developer', 'approver', 'admin', 'auditor'] as $name) {
            Role::firstOrCreate(['name' => $name]);
        }
    }

    public function test_creates_an_admin_from_options_without_prompting(): void
    {
        $this->seedRoles();

        $this->artisan('db-vault:admin', [
            '--name' => 'Ada Admin',
            '--email' => 'ada@dbvault.test',
            '--password' => 'secret-pw',
        ])->assertExitCode(0);

        $user = User::where('email', 'ada@dbvault.test')->first();
        $this->assertNotNull($user);
        $this->assertTrue($user->roles()->where('name', 'admin')->exists());
        $this->assertTrue($user->is_active);
    }

    public function test_can_grant_a_non_admin_role(): void
    {
        $this->seedRoles();

        $this->artisan('db-vault:admin', [
            '--name' => 'Dev One',
            '--email' => 'dev@dbvault.test',
            '--password' => 'secret-pw',
            '--role' => 'developer',
        ])->assertExitCode(0);

        $user = User::where('email', 'dev@dbvault.test')->first();
        $this->assertTrue($user->roles()->where('name', 'developer')->exists());
        $this->assertFalse($user->roles()->where('name', 'admin')->exists());
    }

    public function test_updates_an_existing_user_by_email(): void
    {
        $this->seedRoles();

        $this->artisan('db-vault:admin', [
            '--name' => 'First Name',
            '--email' => 'same@dbvault.test',
            '--password' => 'pw1',
        ])->assertExitCode(0);

        $this->artisan('db-vault:admin', [
            '--name' => 'Renamed',
            '--email' => 'same@dbvault.test',
            '--password' => 'pw2',
        ])->assertExitCode(0);

        $this->assertSame(1, User::where('email', 'same@dbvault.test')->count());
        $this->assertSame('Renamed', User::where('email', 'same@dbvault.test')->value('name'));
    }

    public function test_rejects_an_invalid_role(): void
    {
        $this->seedRoles();

        $this->artisan('db-vault:admin', [
            '--name' => 'X',
            '--email' => 'x@dbvault.test',
            '--password' => 'pw',
            '--role' => 'superuser',
        ])->assertExitCode(1);

        $this->assertNull(User::where('email', 'x@dbvault.test')->first());
    }

    public function test_rejects_an_invalid_email(): void
    {
        $this->seedRoles();

        $this->artisan('db-vault:admin', [
            '--name' => 'X',
            '--email' => 'not-an-email',
            '--password' => 'pw',
        ])->assertExitCode(1);
    }

    public function test_fails_when_roles_are_not_seeded(): void
    {
        // No seedRoles() — the admin role does not exist yet.
        $this->artisan('db-vault:admin', [
            '--name' => 'X',
            '--email' => 'x@dbvault.test',
            '--password' => 'pw',
        ])->assertExitCode(1);
    }
}
