<?php

declare(strict_types=1);

namespace DbVault\Http\Middleware;

use Closure;
use DbVault\Models\Device;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `vault.mtls`: enforces the mTLS leg of authentication. TLS is terminated
 * at the reverse proxy (nginx), which verifies the client certificate and
 * forwards the result via headers (never the raw certificate). This
 * middleware trusts those headers implicitly, so it must only ever run
 * behind a reverse proxy that cannot be bypassed by end users.
 *
 * On success, the verified certificate's DN is shared with the request as
 * the `client_cert_dn` attribute, so controllers/ActivityLog entries can
 * record which device made the call.
 */
class TrustClientCertificate
{
    public function handle(Request $request, Closure $next): Response
    {
        $verified = $request->header(config('dbvault.mtls_verify_header'));
        $dn = $request->header(config('dbvault.mtls_dn_header'));
        $fingerprint = $request->header(config('dbvault.mtls_fingerprint_header'));

        if ($verified !== 'SUCCESS' || empty($dn)) {
            return response()->json([
                'message' => 'A verified client certificate is required to access DB Vault.',
            ], 403);
        }

        // Optionally require the verified cert to match an ENROLLED,
        // non-revoked device. Off by default (device rows informational).
        if (config('dbvault.mtls_require_enrolled_device')) {
            $device = $this->matchDevice($dn, $fingerprint);

            if ($device === null) {
                return response()->json([
                    'message' => 'This client certificate is not enrolled as a trusted device.',
                ], 403);
            }

            $request->attributes->set('client_cert_device_id', $device->id);
        }

        $request->attributes->set('client_cert_dn', $dn);
        $request->attributes->set('client_cert_verified', true);

        return $next($request);
    }

    /**
     * Match the presented certificate to a non-revoked enrolled device.
     *
     * Proxies present the DN in inconsistent forms (field order differs, and
     * some add ST/C), so exact DN-string equality is unreliable. We match in
     * order of strength:
     *   1. exact fingerprint (when the proxy forwards one that was enrolled),
     *   2. exact DN string,
     *   3. normalised Common Name (CN=) extracted from the DN — the stable
     *      per-user identity the vault issues certs with.
     */
    protected function matchDevice(string $dn, ?string $fingerprint): ?Device
    {
        $devices = Device::query()->whereNull('revoked_at')->get();

        if (! empty($fingerprint)) {
            $hit = $devices->firstWhere('cert_fingerprint', $fingerprint);
            if ($hit) {
                return $hit;
            }
        }

        $hit = $devices->firstWhere('cert_dn', $dn);
        if ($hit) {
            return $hit;
        }

        $cn = $this->commonName($dn);
        if ($cn === null) {
            return null;
        }

        return $devices->first(fn (Device $d) => $this->commonName((string) $d->cert_dn) === $cn);
    }

    /**
     * Extract a lower-cased CN from a DN in either "CN=x,OU=..." or
     * RFC2253/OpenSSL slash form, regardless of field order.
     */
    protected function commonName(string $dn): ?string
    {
        if (preg_match('/(?:^|[,\/])\s*CN\s*=\s*([^,\/]+)/i', $dn, $m)) {
            return strtolower(trim($m[1]));
        }

        return null;
    }
}
