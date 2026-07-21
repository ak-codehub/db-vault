<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_me_is_unauthenticated_for_guests(): void
    {
        $this->getJson('/vault/api/me')->assertStatus(401);
    }

    public function test_valid_credentials_without_two_factor_authenticate(): void
    {
        $user = $this->makeUser('developer', ['email' => 'dev@dbvault.test']);

        $this->postJson('/vault/api/login', [
            'email' => 'dev@dbvault.test',
            'password' => 'password',
        ])
            ->assertOk()
            ->assertJson([
                'status' => 'authenticated',
                'user' => ['email' => 'dev@dbvault.test', 'roles' => ['developer']],
            ]);
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $this->makeUser('developer', ['email' => 'dev@dbvault.test']);

        $this->postJson('/vault/api/login', [
            'email' => 'dev@dbvault.test',
            'password' => 'wrong-password',
        ])->assertStatus(422);
    }

    public function test_inactive_user_cannot_authenticate(): void
    {
        $this->makeUser('developer', ['email' => 'off@dbvault.test', 'is_active' => false]);

        $this->postJson('/vault/api/login', [
            'email' => 'off@dbvault.test',
            'password' => 'password',
        ])->assertStatus(422);
    }

    public function test_me_returns_profile_and_counts_for_authenticated_user(): void
    {
        $user = $this->makeUser('approver');

        $this->actingAs($user, 'vault')
            ->getJson('/vault/api/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('server.label', 'test')
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email', 'roles'],
                'server' => ['label'],
                'counts' => ['pendingApprovals', 'activeSessions'],
            ]);
    }
}
