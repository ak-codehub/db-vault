<?php

declare(strict_types=1);

namespace DbVault\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use DbVault\Models\User;
use DbVault\Notifications\EmailOtpNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Second factor for the vault's own login: TOTP (authenticator apps, via
 * pragmarx/google2fa) as the primary factor, plus a numeric email OTP as a
 * fallback channel and single-use recovery codes for lockout recovery.
 *
 * 2FA is opt-in per user: a login only reaches the two-factor-challenge step
 * when the user has a confirmed `two_factor_secret` (see
 * DbVault\Models\User::hasEnabledTwoFactorAuthentication()). Enrollment
 * (generating/confirming a secret) is intentionally not wired to a route
 * here - see the package README for provisioning notes.
 */
class TwoFactorService
{
    public function __construct(protected Google2FA $google2fa)
    {
    }

    /**
     * Generate a new base32 TOTP secret for enrollment.
     */
    public function generateSecretKey(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /**
     * Render an inline SVG QR code for enrolling $secret into an
     * authenticator app under the configured issuer name.
     */
    public function qrCodeSvg(User $user, string $secret): string
    {
        $url = $this->google2fa->getQRCodeUrl(
            (string) config('dbvault.two_factor.issuer', 'DB Vault'),
            $user->email,
            $secret,
        );

        $renderer = new ImageRenderer(new RendererStyle(192), new SvgImageBackEnd());

        return (new Writer($renderer))->writeString($url);
    }

    /**
     * @return list<string> ten one-time recovery codes, plaintext (caller
     *                       is responsible for persisting them, encrypted,
     *                       via DbVault\Models\User::$two_factor_recovery_codes)
     */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 10))
            ->map(fn () => Str::random(10).'-'.Str::random(10))
            ->values()
            ->all();
    }

    /**
     * Verify a 6-digit TOTP code against $user's confirmed secret.
     */
    public function verifyTotp(User $user, string $code): bool
    {
        if ($user->two_factor_secret === null) {
            return false;
        }

        $secret = Crypt::decryptString($user->two_factor_secret);

        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Verify a TOTP $code against a not-yet-persisted $secret (during
     * enrollment) — used before the secret is stored, so verifyTotp() (which
     * reads the stored secret) cannot be used yet.
     */
    public function verifyTotpAgainstSecret(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, $code);
    }

    /**
     * Persist a confirmed enrollment: encrypt and store the TOTP secret and
     * recovery codes, and stamp two_factor_confirmed_at so
     * hasEnabledTwoFactorAuthentication() becomes true. The caller must have
     * already verified a live TOTP code against $secret.
     *
     * @param  list<string>  $recoveryCodes
     */
    public function confirmEnrollment(User $user, string $secret, array $recoveryCodes): void
    {
        $user->forceFill([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($recoveryCodes))),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    /**
     * Verify a recovery code and, if valid, remove it from the user's
     * remaining set so it cannot be reused.
     */
    public function verifyRecoveryCode(User $user, string $code): bool
    {
        if ($user->two_factor_recovery_codes === null) {
            return false;
        }

        $codes = json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];
        $index = array_search($code, $codes, true);

        if ($index === false) {
            return false;
        }

        unset($codes[$index]);

        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes))),
        ])->save();

        return true;
    }

    /**
     * Generate and email a 6-digit one-time code, valid for
     * config('dbvault.two_factor.email_otp_ttl') minutes.
     */
    public function issueEmailOtp(User $user): void
    {
        $code = (string) random_int(100000, 999999);
        $ttl = (int) config('dbvault.two_factor.email_otp_ttl', 10);

        $user->forceFill([
            'email_otp_code' => bcrypt($code),
            'email_otp_expires_at' => now()->addMinutes($ttl),
        ])->save();

        $user->notify(new EmailOtpNotification($code, $ttl));
    }

    /**
     * Verify a previously issued email OTP and, if valid, invalidate it so
     * it cannot be reused.
     */
    public function verifyEmailOtp(User $user, string $code): bool
    {
        if ($user->email_otp_code === null || $user->email_otp_expires_at === null) {
            return false;
        }

        if ($user->email_otp_expires_at->isPast()) {
            return false;
        }

        if (! password_verify($code, $user->email_otp_code)) {
            return false;
        }

        $user->forceFill([
            'email_otp_code' => null,
            'email_otp_expires_at' => null,
        ])->save();

        return true;
    }
}
