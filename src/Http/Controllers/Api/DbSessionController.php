<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Enums\SessionStatus;
use DbVault\Http\Controllers\Controller;
use DbVault\Models\DbSession;
use DbVault\Models\SignonToken;
use DbVault\Services\ActivityLogger;
use DbVault\Services\ProvisionerService;
use DbVault\Services\SignonTokenService;
use DbVault\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Provisioned session management. Developers see their own active/expired
 * sessions, launch phpMyAdmin via a one-time signon token (the underlying
 * MySQL credential is never exposed to the client), and revoke a session
 * early. Admins may also list/revoke across the team.
 */
class DbSessionController extends Controller
{
    public function __construct(
        protected ProvisionerService $provisioner,
        protected ActivityLogger $activityLogger,
    ) {
    }

    /**
     * GET sessions -> { sessions: [...] }. Admins see all live sessions;
     * everyone else sees only their own.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $sessions = DbSession::query()
            ->with(['accessRequest.user', 'accessRequest.grants'])
            ->whereIn('status', SessionStatus::live())
            ->when(
                ! $user->hasRole('admin'),
                fn ($q) => $q->whereHas('accessRequest', fn ($r) => $r->where('user_id', $user->id))
            )
            ->latest()
            ->get()
            ->map(function (DbSession $session) {
                $scope = Presenter::summarizeScope($session->accessRequest->grants);

                return [
                    'id' => $session->id,
                    'developer' => $session->accessRequest->user->name,
                    'username' => $session->mysql_username,
                    'scope' => $scope['summary'],
                    'scopeTone' => $scope['tone'],
                    'expiresIn' => $session->expires_at?->diffForHumans(null, true),
                    'status' => $session->status->badgeStatus(),
                ];
            })
            ->values();

        return response()->json(['sessions' => $sessions]);
    }

    /**
     * POST sessions/{dbSession}/launch -> { pma_url }.
     *
     * Mints a short-lived, single-use signon token (only its hash is stored)
     * and returns the phpMyAdmin signon URL the client opens in a new tab.
     * phpMyAdmin exchanges the token server-side for the real credential;
     * this controller never has access to it.
     */
    public function launch(Request $request, DbSession $dbSession): JsonResponse
    {
        $this->authorizeOwner($request, $dbSession);

        abort_if(
            $dbSession->status !== SessionStatus::Active || $dbSession->isExpired(),
            410,
            'This session is not active.'
        );

        $rawToken = Str::random(64);

        SignonToken::create([
            'db_session_id' => $dbSession->id,
            'token_hash' => Hash::make($rawToken),
            'expires_at' => now()->addMinutes(2),
        ]);

        $this->activityLogger->log($request->user(), 'db_session.pma_launch', $dbSession);

        return response()->json([
            'pma_url' => $this->pmaSignonUrl().'?token='.$rawToken,
        ]);
    }

    /**
     * Where the client opens phpMyAdmin. An explicit dbvault.pma_signon_url
     * wins (legacy external phpMyAdmin over its own origin). Otherwise, when
     * the native proxy is enabled, default to the proxied relative signon path
     * "{path}/pma/signon.php" so the feature works with zero URL config.
     */
    private function pmaSignonUrl(): string
    {
        $configured = trim((string) config('dbvault.pma_signon_url'), " \t");
        if ($configured !== '') {
            return rtrim($configured, '?&');
        }

        if (config('dbvault.pma_proxy_enabled', true) && is_dir((string) config('dbvault.pma_path'))) {
            $path = trim((string) config('dbvault.path', 'vault'), '/');
            $prefix = $path === '' ? 'pma' : $path.'/pma';

            return url($prefix.'/signon.php');
        }

        return rtrim($configured, '?&');
    }

    /**
     * POST sessions/exchange  { token } -> { username, password, host, port, database }
     *
     * Server-to-server endpoint the phpMyAdmin signon script calls to trade a
     * one-time launch token for the session's real temporary credential. It
     * is NOT behind the vault user guard (phpMyAdmin has no vault session);
     * instead it requires the shared secret in config('dbvault.signon_secret')
     * via the X-DbVault-Signon header, and the token itself is single-use and
     * short-lived. The developer's browser never sees the password.
     */
    public function exchange(Request $request): JsonResponse
    {
        $secret = (string) config('dbvault.signon_secret');
        abort_if($secret === '' || ! hash_equals($secret, (string) $request->header('X-DbVault-Signon')), 403, 'Forbidden.');

        $raw = (string) $request->input('token');
        abort_if($raw === '', 422, 'Missing token.');

        $cred = app(SignonTokenService::class)->redeem($raw);
        abort_if($cred === null, 404, 'Invalid or expired token.');

        return response()->json($cred);
    }

    /**
     * POST sessions/{dbSession}/revoke. Drops the temporary MySQL user and
     * marks the session revoked. Owner or admin only.
     */
    public function revoke(Request $request, DbSession $dbSession): JsonResponse
    {
        $this->authorizeOwnerOrAdmin($request, $dbSession);

        $this->provisioner->dropSession($dbSession);
        $dbSession->update(['status' => SessionStatus::Revoked]);

        $this->activityLogger->log($request->user(), 'db_session.revoked', $dbSession);

        return response()->json([
            'status' => 'revoked',
            'session' => ['id' => $dbSession->id, 'status' => $dbSession->status->badgeStatus()],
        ]);
    }

    protected function authorizeOwner(Request $request, DbSession $dbSession): void
    {
        abort_if($dbSession->accessRequest->user_id !== $request->user()->id, 403);
    }

    protected function authorizeOwnerOrAdmin(Request $request, DbSession $dbSession): void
    {
        $user = $request->user();

        abort_if(
            $dbSession->accessRequest->user_id !== $user->id && ! $user->hasRole('admin'),
            403
        );
    }
}
