<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Enums\RequestStatus;
use DbVault\Enums\SessionStatus;
use DbVault\Http\Controllers\Controller;
use DbVault\Http\Resources\UserResource;
use DbVault\Models\AccessRequest;
use DbVault\Models\DbSession;
use DbVault\Models\User;
use DbVault\Services\ActivityLogger;
use DbVault\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Cookie-session authentication for the vault's own guard. Mirrors what
 * Fortify/Sanctum SPA auth provides, but as a plain JSON API (no Inertia):
 *
 *   POST login                 -> two-factor-required | authenticated
 *   POST two-factor-challenge  -> authenticated
 *   POST logout                -> ok
 *   GET  me                    -> current user + server label + nav counts
 *
 * Two-factor is opt-in per user (DbVault\Models\User::hasEnabledTwoFactorAuthentication()).
 * When required, an email OTP is issued as a fallback channel and the
 * challenge additionally accepts a TOTP code or a single-use recovery code.
 */
class AuthController extends Controller
{
    /**
     * Session key holding the pending user id between the credential step and
     * the two-factor challenge.
     */
    private const TWO_FACTOR_SESSION_KEY = 'db-vault.two-factor.id';

    /**
     * Session keys holding a pending, not-yet-persisted enrollment (secret +
     * recovery codes) between the credential step and setup confirmation, used
     * when 2FA is required but the user has not enrolled yet.
     */
    private const SETUP_SESSION_KEY = 'db-vault.two-factor.setup';

    public function login(Request $request, TwoFactorService $twoFactor, ActivityLogger $logger): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(self::TWO_FACTOR_SESSION_KEY, $user->id);

            // Issue the email fallback code; the challenge also accepts a
            // TOTP code from the user's authenticator app.
            $twoFactor->issueEmailOtp($user);

            return response()->json(['status' => 'two-factor-required']);
        }

        // 2FA is required for everyone but this user hasn't enrolled: hand back
        // a fresh secret + recovery codes and make them confirm a TOTP code
        // before the session is granted. Nothing is persisted or logged in
        // until confirmTwoFactorSetup() succeeds — so no lockout, no bypass.
        if ((bool) config('dbvault.two_factor.require', false)) {
            $secret = $twoFactor->generateSecretKey();
            $recoveryCodes = $twoFactor->generateRecoveryCodes();

            $request->session()->put(self::SETUP_SESSION_KEY, [
                'id' => $user->id,
                'secret' => $secret,
                'recovery_codes' => $recoveryCodes,
            ]);

            return response()->json([
                'status' => 'two-factor-setup-required',
                'qr' => $twoFactor->qrCodeSvg($user, $secret),
                'secret' => $secret,
                'recovery_codes' => $recoveryCodes,
            ]);
        }

        return $this->completeLogin($request, $user, $logger);
    }

    /**
     * Complete a forced enrollment: the user scanned the QR returned by
     * login() and submits a TOTP code from their authenticator. On success the
     * secret + recovery codes are persisted (2FA now enabled) and the session
     * is granted. The pending secret lives only in the session until here.
     */
    public function confirmTwoFactorSetup(Request $request, TwoFactorService $twoFactor, ActivityLogger $logger): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string'],
        ]);

        $pending = $request->session()->get(self::SETUP_SESSION_KEY);

        if (! is_array($pending) || empty($pending['id']) || ! ($user = User::find($pending['id']))) {
            return response()->json(['message' => 'No two-factor setup is in progress.'], 419);
        }

        if (! $twoFactor->verifyTotpAgainstSecret((string) $pending['secret'], $validated['code'])) {
            throw ValidationException::withMessages([
                'code' => ['That code did not match. Scan the QR again and enter a fresh code.'],
            ]);
        }

        $twoFactor->confirmEnrollment($user, (string) $pending['secret'], (array) $pending['recovery_codes']);
        $request->session()->forget(self::SETUP_SESSION_KEY);
        $logger->log($user, 'auth.2fa_enrolled', $user);

        return $this->completeLogin($request, $user, $logger, ['via' => 'two-factor-setup']);
    }

    public function twoFactorChallenge(Request $request, TwoFactorService $twoFactor, ActivityLogger $logger): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $userId = $request->session()->get(self::TWO_FACTOR_SESSION_KEY);

        if (! $userId || ! ($user = User::find($userId))) {
            return response()->json(['message' => 'No two-factor challenge is in progress.'], 419);
        }

        $passed = false;

        if (! empty($validated['recovery_code'])) {
            $passed = $twoFactor->verifyRecoveryCode($user, $validated['recovery_code']);
        } elseif (! empty($validated['code'])) {
            $passed = $twoFactor->verifyTotp($user, $validated['code'])
                || $twoFactor->verifyEmailOtp($user, $validated['code']);
        }

        if (! $passed) {
            throw ValidationException::withMessages([
                'code' => ['The provided two-factor code was invalid.'],
            ]);
        }

        $request->session()->forget(self::TWO_FACTOR_SESSION_KEY);

        return $this->completeLogin($request, $user, $logger, ['via' => 'two-factor']);
    }

    public function logout(Request $request, ActivityLogger $logger): JsonResponse
    {
        $guard = (string) config('dbvault.guard', 'vault');
        $user = Auth::guard($guard)->user();

        $logger->log($user instanceof User ? $user : null, 'auth.logout', $user instanceof User ? $user : null);

        Auth::guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Hand the SPA the post-logout token so a subsequent login POST
        // carries a token that matches the freshly-rotated session.
        return response()->json([
            'status' => 'ok',
            'csrf' => $request->session()->token(),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard(config('dbvault.guard', 'vault'))->user();
        $user->loadMissing('roles');

        return response()->json([
            'user' => (new UserResource($user))->resolve($request),
            'server' => [
                'label' => config('dbvault.server_label'),
            ],
            'counts' => [
                'pendingApprovals' => AccessRequest::where('status', RequestStatus::PendingApproval)->count(),
                'activeSessions' => DbSession::where('status', SessionStatus::Active)->count(),
            ],
            // Current CSRF token, so the SPA can re-sync after the session
            // was rotated on login (see completeLogin()).
            'csrf' => $request->session()->token(),
        ]);
    }

    /**
     * Log the user into the vault guard, rotate the session, record the
     * login, and return the authenticated payload the SPA's useAuth composable
     * expects.
     *
     * @param  array<string, mixed>  $meta
     */
    private function completeLogin(Request $request, User $user, ActivityLogger $logger, array $meta = []): JsonResponse
    {
        Auth::guard(config('dbvault.guard', 'vault'))->login($user);
        $request->session()->regenerate();

        $logger->log($user, 'auth.login', $user, $meta);

        return response()->json([
            'status' => 'authenticated',
            'user' => (new UserResource($user->loadMissing('roles')))->resolve($request),
            // The session token was just rotated by regenerate(); return it so
            // the SPA replaces its stale boot token before the next mutation.
            'csrf' => $request->session()->token(),
        ]);
    }
}
