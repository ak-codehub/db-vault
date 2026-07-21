<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Enums\RequestStatus;
use DbVault\Http\Controllers\Controller;
use DbVault\Http\Requests\StoreAccessRequestRequest;
use DbVault\Models\AccessRequest;
use DbVault\Models\RequestGrant;
use DbVault\Support\Presenter;
use DbVault\Services\ActivityLogger;
use DbVault\Services\RequestStateMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Developer-facing request lifecycle as a JSON API: list your requests,
 * submit a new one (which goes straight into the approval queue), view a
 * single request with its matrix/approval/session/audit context, and cancel
 * one that is still pending. Response shapes mirror resources/js/api.js and
 * the Requests/* views one-to-one.
 */
class AccessRequestController extends Controller
{
    public function __construct(
        protected RequestStateMachine $stateMachine,
        protected ActivityLogger $activityLogger,
    ) {
    }

    /**
     * GET requests -> { requests: [...] }. Admins see everything; everyone
     * else sees only their own requests.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $requests = AccessRequest::query()
            ->with('grants')
            ->when(! $user->hasRole('admin'), fn ($q) => $q->where('user_id', $user->id))
            ->latest()
            ->get()
            ->map(function (AccessRequest $r) {
                $scope = Presenter::summarizeScope($r->grants);

                return [
                    'id' => $r->id,
                    'database' => $r->target_database,
                    'reason' => $r->reason,
                    'scope' => $scope['summary'],
                    'duration' => Presenter::formatDuration($r->duration_minutes),
                    'status' => $r->status->badgeStatus(),
                    'createdAt' => $r->created_at?->diffForHumans(),
                ];
            })
            ->values();

        return response()->json(['requests' => $requests]);
    }

    /**
     * POST requests -> 201 { request: {...} }.
     *
     * The request is created as Draft and immediately transitioned into
     * PendingApproval via the state machine (which stamps requested_at and
     * guards the edge). Grants are expanded from the SPA's flat
     * grants:[{table, privileges[]}] payload into one RequestGrant row per
     * (table, privilege). All of it is one transaction.
     */
    public function store(StoreAccessRequestRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $accessRequest = DB::transaction(function () use ($request, $validated) {
            $accessRequest = AccessRequest::create([
                'user_id' => $request->user()->id,
                'target_database' => $validated['target_database'],
                'duration_minutes' => $validated['duration_minutes'],
                'reason' => $validated['reason'],
                'status' => RequestStatus::Draft,
                'requested_at' => null,
            ]);

            foreach ($validated['grants'] as $grant) {
                foreach (array_unique($grant['privileges']) as $privilege) {
                    RequestGrant::create([
                        'access_request_id' => $accessRequest->id,
                        'table_name' => $grant['table'],
                        'column_name' => null,
                        'privilege' => strtoupper($privilege),
                    ]);
                }
            }

            $this->stateMachine->transition($accessRequest, RequestStatus::PendingApproval);

            $this->activityLogger->log($request->user(), 'access_request.submitted', $accessRequest);

            return $accessRequest;
        });

        return response()->json([
            'request' => [
                'id' => $accessRequest->id,
                'status' => $accessRequest->status->badgeStatus(),
            ],
        ], 201);
    }

    /**
     * GET requests/{accessRequest} -> { request, matrix, approval, session, auditQueries }.
     */
    public function show(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $this->authorizeVault('view', $accessRequest);

        $accessRequest->load(['grants', 'approval.approver', 'dbSession.auditQueries', 'user']);

        $session = $accessRequest->dbSession;

        return response()->json([
            'request' => [
                'id' => $accessRequest->id,
                'database' => $accessRequest->target_database,
                'duration' => Presenter::formatDuration($accessRequest->duration_minutes),
                'reason' => $accessRequest->reason,
                'status' => $accessRequest->status->badgeStatus(),
                'developer' => $accessRequest->user->name,
                'expiresIn' => $session?->expires_at?->diffForHumans(null, true),
            ],
            'matrix' => Presenter::buildMatrixRows($accessRequest->grants),
            'approval' => $accessRequest->approval ? [
                'status' => $accessRequest->approval->decision === 'approve' ? 'approved' : 'rejected',
                'note' => $accessRequest->approval->note,
                'approver' => $accessRequest->approval->approver?->name,
                'decidedAt' => $accessRequest->approval->decided_at?->toIso8601String(),
            ] : null,
            'session' => $session ? [
                'id' => $session->id,
                'username' => $session->mysql_username,
                'status' => $session->status->badgeStatus(),
            ] : null,
            'auditQueries' => $session
                ? $session->auditQueries->map(fn ($q) => [
                    'at' => $q->executed_at?->format('H:i:s'),
                    'statement' => $q->statement,
                ])->values()
                : [],
        ]);
    }

    /**
     * POST requests/{accessRequest}/cancel. Owner-only, and only while the
     * request is still pending (enforced by the policy and again by the
     * state machine).
     */
    public function cancel(Request $request, AccessRequest $accessRequest): JsonResponse
    {
        $this->authorizeVault('cancel', $accessRequest);

        $this->stateMachine->transition($accessRequest, RequestStatus::Cancelled);

        $this->activityLogger->log($request->user(), 'access_request.cancelled', $accessRequest);

        return response()->json([
            'request' => [
                'id' => $accessRequest->id,
                'status' => $accessRequest->status->badgeStatus(),
            ],
        ]);
    }
}
