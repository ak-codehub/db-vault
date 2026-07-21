<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Http\Middleware\TrustClientCertificate;
use DbVault\Models\Device;
use DbVault\Tests\TestCase;
use Illuminate\Http\Request;

class MtlsDeviceEnforcementTest extends TestCase
{
    private function makeRequest(array $headers): Request
    {
        $request = Request::create('/vault/api/dashboard', 'GET');
        foreach ($headers as $k => $v) {
            $request->headers->set($k, $v);
        }

        return $request;
    }

    private function passThrough(): \Closure
    {
        return fn () => response('ok');
    }

    public function test_missing_verified_cert_is_rejected(): void
    {
        $mw = new TrustClientCertificate();
        $response = $mw->handle($this->makeRequest([]), $this->passThrough());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_verified_cert_passes_when_enforcement_is_off(): void
    {
        config()->set('dbvault.mtls_require_enrolled_device', false);

        $mw = new TrustClientCertificate();
        $response = $mw->handle($this->makeRequest([
            config('dbvault.mtls_verify_header') => 'SUCCESS',
            config('dbvault.mtls_dn_header') => 'CN=anyone',
        ]), $this->passThrough());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_verified_cert_without_enrolled_device_is_rejected_when_enforced(): void
    {
        config()->set('dbvault.mtls_require_enrolled_device', true);

        $mw = new TrustClientCertificate();
        $response = $mw->handle($this->makeRequest([
            config('dbvault.mtls_verify_header') => 'SUCCESS',
            config('dbvault.mtls_dn_header') => 'CN=unknown',
        ]), $this->passThrough());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_enrolled_device_passes_when_enforced(): void
    {
        config()->set('dbvault.mtls_require_enrolled_device', true);

        $user = $this->makeUser('developer');
        Device::create([
            'user_id' => $user->id,
            'cert_fingerprint' => 'fp-known',
            'cert_dn' => 'CN=known',
            'enrolled_at' => now(),
        ]);

        $mw = new TrustClientCertificate();
        $response = $mw->handle($this->makeRequest([
            config('dbvault.mtls_verify_header') => 'SUCCESS',
            config('dbvault.mtls_dn_header') => 'CN=known',
            config('dbvault.mtls_fingerprint_header') => 'fp-known',
        ]), $this->passThrough());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_matches_device_by_normalised_cn_when_dn_format_differs(): void
    {
        config()->set('dbvault.mtls_require_enrolled_device', true);

        $user = $this->makeUser('developer');
        // Enrolled in the CA-service format...
        Device::create([
            'user_id' => $user->id,
            'cert_fingerprint' => 'fp-cn',
            'cert_dn' => 'CN=dev@example.com,OU=devices,O=DB Vault,emailAddress=dev@example.com',
            'enrolled_at' => now(),
        ]);

        // ...but the proxy forwards a DIFFERENT DN order with extra fields and
        // a SHA-1 fingerprint that was never enrolled. CN match must succeed.
        $mw = new TrustClientCertificate();
        $response = $mw->handle($this->makeRequest([
            config('dbvault.mtls_verify_header') => 'SUCCESS',
            config('dbvault.mtls_dn_header') => 'ST=Some-State,C=AU,emailAddress=dev@example.com,CN=dev@example.com,OU=devices,O=DB Vault',
            config('dbvault.mtls_fingerprint_header') => 'SHA256:different',
        ]), $this->passThrough());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_revoked_device_is_rejected_when_enforced(): void
    {
        config()->set('dbvault.mtls_require_enrolled_device', true);

        $user = $this->makeUser('developer');
        Device::create([
            'user_id' => $user->id,
            'cert_fingerprint' => 'fp-revoked',
            'cert_dn' => 'CN=revoked',
            'enrolled_at' => now(),
            'revoked_at' => now(),
        ]);

        $mw = new TrustClientCertificate();
        $response = $mw->handle($this->makeRequest([
            config('dbvault.mtls_verify_header') => 'SUCCESS',
            config('dbvault.mtls_dn_header') => 'CN=revoked',
            config('dbvault.mtls_fingerprint_header') => 'fp-revoked',
        ]), $this->passThrough());

        $this->assertSame(403, $response->getStatusCode());
    }
}
