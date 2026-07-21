<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Enums\RequestStatus;
use DbVault\Enums\SessionStatus;
use DbVault\Http\Controllers\Controller;
use DbVault\Models\AccessRequest;
use DbVault\Models\Approval;
use DbVault\Models\AuditQuery;
use DbVault\Models\DbSession;
use DbVault\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET dashboard — the at-a-glance landing payload for
 * resources/js/Views/Dashboard.vue: headline stats, the active-session
 * table, and (for approvers/admins) the requests awaiting their decision.
 */
class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isApprover = $user->hasRole('approver') || $user->hasRole('admin');

        $activeSessions = DbSession::query()
            ->with(['accessRequest.user', 'accessRequest.grants'])
            ->where('status', SessionStatus::Active)
            ->latest()
            ->get();

        $activeSessionRows = $activeSessions->take(10)->map(function (DbSession $session) {
            $scope = Presenter::summarizeScope($session->accessRequest->grants);

            return [
                'id' => $session->id,
                'developer' => $session->accessRequest->user->name,
                'username' => $session->mysql_username,
                'scope' => $scope['summary'],
                'scopeTone' => $scope['tone'],
                'expiresIn' => $session->expires_at?->diffForHumans(null, true),
                'status' => $session->status->badgeStatus(),
                // Launch is a state-changing POST (mints a one-time token),
                // so the list omits a direct href — the session/detail views
                // call POST sessions/{id}/launch instead.
                'pmaUrl' => null,
            ];
        })->values();

        $pendingApprovals = $isApprover
            ? AccessRequest::query()
                ->with(['user', 'grants'])
                ->where('status', RequestStatus::PendingApproval)
                ->oldest('requested_at')
                ->limit(10)
                ->get()
                ->map(fn (AccessRequest $r) => [
                    'id' => $r->id,
                    'developer' => $r->user->name,
                    'requestedAgo' => $r->requested_at?->diffForHumans(),
                    'summary' => $this->summarize($r),
                ])
                ->values()
            : collect();

        return response()->json([
            'stats' => [
                'activeSessions' => $activeSessions->count(),
                'pendingApprovals' => AccessRequest::where('status', RequestStatus::PendingApproval)->count(),
                'grantedToday' => Approval::where('decision', 'approve')->whereDate('decided_at', today())->count(),
                'queriesAudited' => AuditQuery::whereDate('executed_at', today())->count(),
            ],
            'activeSessions' => $activeSessionRows,
            'pendingApprovals' => $pendingApprovals,
        ]);
    }

    /**
     * Build the short HTML-safe summary line the dashboard renders per pending
     * request (Dashboard.vue binds it with v-html).
     */
    private function summarize(AccessRequest $request): string
    {
        $scope = Presenter::summarizeScope($request->grants);
        $tables = $request->grants->pluck('table_name')->unique()->implode(', ') ?: 'all tables';

        return sprintf(
            '%s access to %s · %s · "%s"',
            $scope['tone'] === 'warn' ? 'Write' : 'Read',
            e($tables),
            Presenter::formatDuration($request->duration_minutes),
            e($request->reason),
        );
    }
}
