<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Enums\RequestStatus;
use DbVault\Models\AccessRequest;
use DbVault\Models\RequestGrant;
use DbVault\Tests\TestCase;

class AccessRequestTest extends TestCase
{
    public function test_developer_can_submit_a_scoped_request(): void
    {
        $developer = $this->makeUser('developer');

        $response = $this->actingAs($developer, 'vault')->postJson('/vault/api/requests', [
            'target_database' => 'appdb',
            'duration_minutes' => 60,
            'reason' => 'Investigate a checkout bug',
            'grants' => [
                ['table' => 'orders', 'privileges' => ['select', 'update']],
                ['table' => 'order_items', 'privileges' => ['select']],
            ],
        ]);

        $response->assertStatus(201)->assertJsonPath('request.status', 'pending');

        $request = AccessRequest::first();
        $this->assertNotNull($request);
        $this->assertSame(RequestStatus::PendingApproval, $request->status);
        $this->assertNotNull($request->requested_at);
        $this->assertSame($developer->id, $request->user_id);

        // Three grants expanded from the matrix, all upper-cased.
        $this->assertSame(3, RequestGrant::count());
        $this->assertEqualsCanonicalizing(
            ['SELECT', 'UPDATE', 'SELECT'],
            RequestGrant::pluck('privilege')->map(fn ($p) => $p->value)->all(),
        );
    }

    public function test_forbidden_privilege_is_rejected(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')->postJson('/vault/api/requests', [
            'target_database' => 'appdb',
            'duration_minutes' => 60,
            'reason' => 'Trying to sneak a DROP through',
            'grants' => [
                ['table' => 'orders', 'privileges' => ['select', 'drop']],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, AccessRequest::count());
    }

    public function test_a_request_with_a_non_identifier_table_name_is_rejected(): void
    {
        $developer = $this->makeUser('developer');

        // A crafted table name must be rejected at the boundary so it never
        // reaches the grant-SQL builder on the admin connection.
        $this->actingAs($developer, 'vault')->postJson('/vault/api/requests', [
            'target_database' => 'appdb',
            'duration_minutes' => 60,
            'reason' => 'Attempting identifier injection through the table name',
            'grants' => [
                ['table' => "orders` ON *.* TO 'x'@'%'; -- ", 'privileges' => ['select']],
            ],
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['grants.0.table']);

        $this->assertSame(0, AccessRequest::count());
    }

    public function test_a_request_against_a_disallowed_database_is_rejected(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')->postJson('/vault/api/requests', [
            'target_database' => 'some_other_db',
            'duration_minutes' => 60,
            'reason' => 'Wrong database',
            'grants' => [
                ['table' => 'orders', 'privileges' => ['select']],
            ],
        ])->assertStatus(422);
    }

    public function test_a_developer_only_sees_their_own_requests(): void
    {
        $mine = $this->makeUser('developer');
        $other = $this->makeUser('developer');

        AccessRequest::create([
            'user_id' => $other->id,
            'target_database' => 'appdb',
            'duration_minutes' => 60,
            'reason' => 'Not mine',
            'status' => RequestStatus::PendingApproval,
            'requested_at' => now(),
        ]);

        $this->actingAs($mine, 'vault')
            ->getJson('/vault/api/requests')
            ->assertOk()
            ->assertJsonCount(0, 'requests');
    }
}
