<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Enums\Privilege;
use DbVault\Enums\RequestStatus;
use DbVault\Enums\SessionStatus;
use DbVault\Models\AccessRequest;
use DbVault\Models\DbSession;
use DbVault\Models\RequestGrant;
use DbVault\Models\User;
use DbVault\Tests\TestCase;

class ApprovalFlowTest extends TestCase
{
    public function test_approver_approves_a_request_and_a_session_is_provisioned(): void
    {
        $developer = $this->makeUser('developer');
        $approver = $this->makeUser('approver');
        $request = $this->pendingRequest($developer);

        $this->actingAs($approver, 'vault')
            ->postJson("/vault/api/approvals/{$request->id}/approve", ['note' => 'Looks fine'])
            ->assertOk()
            ->assertJsonPath('status', 'approved');

        $request->refresh();
        $this->assertSame(RequestStatus::Active, $request->status);

        $session = DbSession::where('access_request_id', $request->id)->first();
        $this->assertNotNull($session);
        $this->assertSame(SessionStatus::Pending, $session->status);
        $this->assertStringStartsWith('dbv_', $session->mysql_username);
    }

    public function test_approver_cannot_decide_on_their_own_request(): void
    {
        $approver = $this->makeUser('approver');
        $request = $this->pendingRequest($approver);

        $this->actingAs($approver, 'vault')
            ->postJson("/vault/api/approvals/{$request->id}/approve")
            ->assertStatus(403);

        $this->assertSame(RequestStatus::PendingApproval, $request->fresh()->status);
    }

    public function test_rejecting_a_request_closes_it_without_a_session(): void
    {
        $developer = $this->makeUser('developer');
        $approver = $this->makeUser('approver');
        $request = $this->pendingRequest($developer);

        $this->actingAs($approver, 'vault')
            ->postJson("/vault/api/approvals/{$request->id}/reject", ['note' => 'Too broad'])
            ->assertOk()
            ->assertJsonPath('status', 'rejected');

        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
        $this->assertSame(0, DbSession::count());
    }

    public function test_a_plain_developer_cannot_reach_the_approval_queue(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/approvals')
            ->assertStatus(403);
    }

    private function pendingRequest(User $owner): AccessRequest
    {
        $request = AccessRequest::create([
            'user_id' => $owner->id,
            'target_database' => 'appdb',
            'duration_minutes' => 60,
            'reason' => 'Needs review',
            'status' => RequestStatus::PendingApproval,
            'requested_at' => now(),
        ]);

        RequestGrant::create([
            'access_request_id' => $request->id,
            'table_name' => 'orders',
            'column_name' => null,
            'privilege' => Privilege::Select->value,
        ]);

        return $request;
    }
}
