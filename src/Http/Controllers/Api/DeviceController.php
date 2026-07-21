<?php

declare(strict_types=1);

namespace DbVault\Http\Controllers\Api;

use DbVault\Http\Controllers\Controller;
use DbVault\Http\Resources\DeviceResource;
use DbVault\Models\Device;
use DbVault\Models\User;
use DbVault\Services\CertificateAuthorityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-only management of enrolled mTLS client-certificate devices.
 *
 * A device binds a user to a specific client certificate (by DN and/or
 * fingerprint) that the reverse proxy verifies before the request ever
 * reaches the app. Enrolling a device here is how an operator authorises a
 * particular laptop/cert to reach the vault as that user; revoking it
 * withdraws that authorisation. Enforcement lives in the
 * TrustClientCertificate middleware (gated by
 * config('dbvault.mtls_require_enrolled_device')).
 */
class DeviceController extends Controller
{
    public function index(CertificateAuthorityService $ca): JsonResponse
    {
        $devices = Device::query()
            ->with('user')
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'devices' => DeviceResource::collection($devices)->resolve(),
            // Lets the SPA show/hide the "Issue certificate" action.
            'can_issue' => $ca->isConfigured(),
        ]);
    }

    /**
     * Issue a fresh client certificate for a user, enrol it as a device, and
     * return the downloadable PKCS#12 bundle (base64) plus its one-time
     * password. The private key exists only in this bundle — it is never
     * stored server-side; only the cert identity is persisted.
     */
    public function issue(Request $request, CertificateAuthorityService $ca): JsonResponse
    {
        if (! $ca->isConfigured()) {
            return response()->json([
                'message' => 'Certificate issuance is not available — no CA is configured. Run `php artisan dbvault:make-ca`.',
            ], 422);
        }

        $connection = config('dbvault.connection');
        $usersTable = $connection ? "{$connection}.vault_users" : 'vault_users';

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists($usersTable, 'id')],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $user = User::findOrFail($validated['user_id']);
        $issued = $ca->issueForUser($user, $validated['label'] ?? null);

        $device = Device::create([
            'user_id' => $user->id,
            'cert_fingerprint' => $issued['fingerprint'],
            'cert_dn' => $issued['dn'],
            'label' => $validated['label'] ?? null,
            'enrolled_at' => now(),
        ]);

        return response()->json([
            'device' => (new DeviceResource($device->load('user')))->resolve(),
            // Handed to the admin ONCE to pass to the user out-of-band.
            'pkcs12_base64' => base64_encode($issued['p12']),
            'password' => $issued['password'],
            'filename' => 'dbvault-'.preg_replace('/[^a-z0-9]+/i', '-', $user->email).'.p12',
        ], 201);
    }

    public function store(Request $request): JsonResponse
    {
        $connection = config('dbvault.connection');
        $usersTable = $connection ? "{$connection}.vault_users" : 'vault_users';
        $devicesTable = $connection ? "{$connection}.vault_devices" : 'vault_devices';

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists($usersTable, 'id')],
            'cert_fingerprint' => ['required', 'string', 'max:191', Rule::unique($devicesTable, 'cert_fingerprint')],
            'cert_dn' => ['required', 'string', 'max:255'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $device = Device::create([
            'user_id' => $validated['user_id'],
            'cert_fingerprint' => trim($validated['cert_fingerprint']),
            'cert_dn' => trim($validated['cert_dn']),
            'label' => $validated['label'] ?? null,
            'enrolled_at' => now(),
        ]);

        return response()->json([
            'device' => (new DeviceResource($device->load('user')))->resolve(),
        ], 201);
    }

    /**
     * Revoke (soft) or reactivate a device. Body: { revoked: bool }.
     */
    public function update(Request $request, Device $device): JsonResponse
    {
        $validated = $request->validate([
            'revoked' => ['required', 'boolean'],
        ]);

        $device->revoked_at = $validated['revoked'] ? now() : null;
        $device->save();

        return response()->json([
            'device' => (new DeviceResource($device->load('user')))->resolve(),
        ]);
    }
}
