<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Enums\RequestStatus;
use DbVault\Enums\SessionStatus;
use DbVault\Models\AccessRequest;
use DbVault\Models\DbSession;
use DbVault\Models\SignonToken;
use DbVault\Models\User;
use DbVault\Tests\TestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class SignonExchangeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('dbvault.signon_secret', 'test-signon-secret');
        config()->set('dbvault.target_database', 'appdb');
    }

    private function activeSessionWithToken(string $rawToken): DbSession
    {
        $user = $this->makeUser('developer');
        $request = AccessRequest::create([
            'user_id' => $user->id,
            'target_database' => 'appdb',
            'duration_minutes' => 60,
            'reason' => 'x',
            'status' => RequestStatus::Active,
            'requested_at' => now(),
        ]);

        $session = DbSession::create([
            'access_request_id' => $request->id,
            'mysql_username' => 'dbv_test_req'.$request->id,
            'secret' => 'super-secret-pw',
            'status' => SessionStatus::Active,
            'max_connections' => 3,
            'provisioned_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        SignonToken::create([
            'db_session_id' => $session->id,
            'token_hash' => Hash::make($rawToken),
            'expires_at' => now()->addMinutes(2),
        ]);

        return $session;
    }

    public function test_valid_token_and_secret_returns_credentials(): void
    {
        $raw = Str::random(64);
        $session = $this->activeSessionWithToken($raw);

        $this->withHeader('X-DbVault-Signon', 'test-signon-secret')
            ->postJson('/vault/api/sessions/exchange', ['token' => $raw])
            ->assertOk()
            ->assertJsonPath('username', $session->mysql_username)
            ->assertJsonPath('password', 'super-secret-pw')
            ->assertJsonPath('database', 'appdb');
    }

    public function test_token_is_single_use(): void
    {
        $raw = Str::random(64);
        $this->activeSessionWithToken($raw);

        $this->withHeader('X-DbVault-Signon', 'test-signon-secret')
            ->postJson('/vault/api/sessions/exchange', ['token' => $raw])->assertOk();

        // Second use is rejected.
        $this->withHeader('X-DbVault-Signon', 'test-signon-secret')
            ->postJson('/vault/api/sessions/exchange', ['token' => $raw])->assertStatus(404);
    }

    public function test_wrong_shared_secret_is_forbidden(): void
    {
        $raw = Str::random(64);
        $this->activeSessionWithToken($raw);

        $this->withHeader('X-DbVault-Signon', 'nope')
            ->postJson('/vault/api/sessions/exchange', ['token' => $raw])->assertStatus(403);
    }

    public function test_missing_secret_is_forbidden(): void
    {
        $this->postJson('/vault/api/sessions/exchange', ['token' => 'x'])->assertStatus(403);
    }

    public function test_unknown_token_is_not_found(): void
    {
        $this->withHeader('X-DbVault-Signon', 'test-signon-secret')
            ->postJson('/vault/api/sessions/exchange', ['token' => 'does-not-exist'])->assertStatus(404);
    }

    public function test_expired_token_is_rejected(): void
    {
        $raw = Str::random(64);
        $session = $this->activeSessionWithToken($raw);
        SignonToken::where('db_session_id', $session->id)->update(['expires_at' => now()->subMinute()]);

        $this->withHeader('X-DbVault-Signon', 'test-signon-secret')
            ->postJson('/vault/api/sessions/exchange', ['token' => $raw])->assertStatus(404);
    }
}
