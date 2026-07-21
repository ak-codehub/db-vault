<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Enums\SessionStatus;
use DbVault\Http\Controllers\Controller;
use DbVault\Models\DbSession;
use DbVault\Models\SignonToken;
use DbVault\Services\ActivityLogger;
use DbVault\Services\ProvisionerService;
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
            'pma_url' => rtrim((string) config('dbvault.pma_signon_url'), '?&').'?token='.$rawToken,
        ]);
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

        // Find a still-valid token by comparing the hash. Tokens are few and
        // short-lived, so scanning the small unused/unexpired set is fine.
        $candidate = SignonToken::query()
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get()
            ->first(fn (SignonToken $t) => Hash::check($raw, $t->token_hash));

        abort_if($candidate === null, 404, 'Invalid or expired token.');

        $candidate->forceFill(['used_at' => now()])->save(); // single-use

        $session = $candidate->dbSession;
        abort_if($session->status !== SessionStatus::Active || $session->isExpired(), 410, 'Session not active.');

        return response()->json([
            'username' => $session->mysql_username,
            'password' => $session->secret,
            'host' => config('dbvault.provisioner.host', config('dbvault.rds_host', '127.0.0.1')),
            'port' => (int) config('dbvault.provisioner.port', config('dbvault.rds_port', 3306)),
            'database' => config('dbvault.target_database'),
        ]);
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
