<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Models\User;
use DbVault\Tests\TestCase;

class UserManagementTest extends TestCase
{
    public function test_unique_email_rule_targets_the_vault_connection(): void
    {
        // Regression guard: the unique-email validation must query the vault's
        // own connection, not the host's default connection (where the
        // vault_users table does not exist once the vault has its own DB).
        config()->set('dbvault.connection', 'my_vault_conn');

        $controller = new \DbVault\Http\Controllers\Api\UserController();
        $method = new \ReflectionMethod($controller, 'uniqueVaultEmail');
        $method->setAccessible(true);

        $rule = (string) $method->invoke($controller);

        $this->assertStringContainsString('my_vault_conn.vault_users', $rule);
    }

    public function test_admin_can_onboard_a_new_user_with_roles(): void
    {
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin, 'vault')->postJson('/vault/api/users', [
            'name' => 'Arun Kumar',
            'email' => 'arun@example.com',
            'password' => 'temp-password-123',
            'roles' => ['developer', 'approver'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('user.email', 'arun@example.com')
            ->assertJsonPath('user.is_active', true);

        $created = User::where('email', 'arun@example.com')->firstOrFail();
        $this->assertTrue($created->fresh()->is_active);
        $this->assertEqualsCanonicalizing(
            ['developer', 'approver'],
            $created->roles->pluck('name')->all(),
        );
        // Password stored hashed, never plain.
        $this->assertNotSame('temp-password-123', $created->password);
    }

    public function test_password_below_minimum_length_is_rejected(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'vault')->postJson('/vault/api/users', [
            'name' => 'Weak Pass',
            'email' => 'weak@example.com',
            'password' => 'short',
            'roles' => ['developer'],
        ])->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertNull(User::where('email', 'weak@example.com')->first());
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $this->makeUser('developer', ['email' => 'taken@example.com']);

        $this->actingAs($admin, 'vault')->postJson('/vault/api/users', [
            'name' => 'Dupe',
            'email' => 'taken@example.com',
            'password' => 'temp-password-123',
            'roles' => ['developer'],
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_admin_can_deactivate_another_user(): void
    {
        $admin = $this->makeUser('admin');
        $target = $this->makeUser('developer');

        $this->actingAs($admin, 'vault')
            ->patchJson("/vault/api/users/{$target->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('user.is_active', false);

        $this->assertFalse($target->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_themselves(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'vault')
            ->patchJson("/vault/api/users/{$admin->id}", ['is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_cannot_strip_their_own_admin_role(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'vault')
            ->patchJson("/vault/api/users/{$admin->id}", ['roles' => ['developer']])
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->hasRole('admin'));
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')->getJson('/vault/api/users')->assertStatus(403);
        $this->actingAs($developer, 'vault')->postJson('/vault/api/users', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'temp-password-123', 'roles' => ['developer'],
        ])->assertStatus(403);
    }

    public function test_user_management_requires_authentication(): void
    {
        $this->getJson('/vault/api/users')->assertStatus(401);
    }
}
