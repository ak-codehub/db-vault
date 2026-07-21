<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Enums\RequestStatus;
use DbVault\Http\Controllers\Controller;
use DbVault\Models\AccessRequest;
use DbVault\Models\Approval;
use DbVault\Support\Presenter;
use DbVault\Services\ActivityLogger;
use DbVault\Services\ProvisionerService;
use DbVault\Services\RequestStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Approver/admin-facing queue. GET approvals lists pending requests with
 * their full privilege matrix; approve/reject record the decision, drive the
 * lifecycle transitions, and (on approval) provision a session. Route access
 * is gated by vault.role:approver,admin; this controller additionally refuses
 * to let an approver decide on their own request.
 */
class ApprovalController extends Controller
{
    public function __construct(
        protected ProvisionerService $provisioner,
        protected RequestStateMachine $stateMachine,
        protected ActivityLogger $activityLogger,
    ) {
    }

    /**
     * GET approvals -> { approvals: [...] }. Each item's `id` is the access
     * request id the approve/reject endpoints act on.
     */
    public function index(): JsonResponse
    {
        $approvals = AccessRequest::query()
            ->with(['user', 'grants'])
            ->where('status', RequestStatus::PendingApproval)
            ->oldest('requested_at')
            ->get()
            ->map(fn (AccessRequest $r) => [
                'id' => $r->id,
                'developer' => $r->user->name,
                'requestedAgo' => $r->requested_at?->diffForHumans(),
                'database' => $r->target_database,
                'duration' => Presenter::formatDuration($r->duration_minutes),
                'reason' => $r->reason,
                'matrix' => Presenter::buildMatrixRows($r->grants),
            ])
            ->values();

        return response()->json(['approvals' => $approvals]);
    }

    /**
     * POST approvals/{accessRequest}/approve.
     *
     * Records the decision, transitions PendingApproval -> Approved,
     * provisions a session, then transitions Approved -> Active — all in one
     * transaction so a provisioning failure (e.g. a forbidden privilege that
     * somehow slipped through) rolls the approval back rather than leaving the
     * request Approved with no session.
     */
    public function approve(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $this->guardDecision($request, $accessRequest);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            DB::transaction(function () use ($request, $accessRequest, $validated) {
                Approval::create([
                    'access_request_id' => $accessRequest->id,
                    'approver_id' => $request->user()->id,
                    'decision' => 'approve',
                    'note' => $validated['note'] ?? null,
                    'decided_at' => now(),
                ]);

                $this->stateMachine->transition($accessRequest, RequestStatus::Approved);
                $this->provisioner->createSession($accessRequest);
                $this->stateMachine->transition($accessRequest, RequestStatus::Active);

                $this->activityLogger->log(
                    $request->user(),
                    'access_request.approved',
                    $accessRequest,
                    ['note' => $validated['note'] ?? null],
                );
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message' => "Request #{$accessRequest->id} could not be provisioned and was not approved.",
            ], 422);
        }

        return response()->json([
            'status' => 'approved',
            'request' => ['id' => $accessRequest->id, 'status' => $accessRequest->status->badgeStatus()],
        ]);
    }

    /**
     * POST approvals/{accessRequest}/reject.
     */
    public function reject(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $this->guardDecision($request, $accessRequest);

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $accessRequest, $validated) {
            Approval::create([
                'access_request_id' => $accessRequest->id,
                'approver_id' => $request->user()->id,
                'decision' => 'reject',
                'note' => $validated['note'] ?? null,
                'decided_at' => now(),
            ]);

            $this->stateMachine->transition($accessRequest, RequestStatus::Rejected);

            $this->activityLogger->log(
                $request->user(),
                'access_request.rejected',
                $accessRequest,
                ['note' => $validated['note'] ?? null],
            );
        });

        return response()->json([
            'status' => 'rejected',
            'request' => ['id' => $accessRequest->id, 'status' => $accessRequest->status->badgeStatus()],
        ]);
    }

    /**
     * Refuse a decision on a non-pending request or on the approver's own
     * request. (Role membership is already enforced by route middleware.)
     */
    protected function guardDecision(Request $request, AccessRequest $accessRequest): void
    {
        abort_if(
            $accessRequest->user_id === $request->user()->id,
            403,
            'You cannot approve or reject your own request.'
        );

        abort_unless(
            $accessRequest->status === RequestStatus::PendingApproval,
            422,
            "Request #{$accessRequest->id} is not awaiting a decision."
        );
    }
}
