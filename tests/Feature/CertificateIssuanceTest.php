<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Models\Device;
use DbVault\Services\CertificateAuthorityService;
use DbVault\Tests\TestCase;

class CertificateIssuanceTest extends TestCase
{
    private string $caDir;

    protected function setUp(): void
    {
        parent::setUp();

        // Build a throwaway CA on disk for the test and point config at it.
        $this->caDir = sys_get_temp_dir().'/dbvault-ca-'.uniqid();
        mkdir($this->caDir, 0700, true);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['organizationName' => 'DB Vault', 'commonName' => 'Test CA'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha256'], 1);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);
        file_put_contents($this->caDir.'/ca.crt', $certPem);
        file_put_contents($this->caDir.'/ca.key', $keyPem);

        config()->set('dbvault.ca.cert_path', $this->caDir.'/ca.crt');
        config()->set('dbvault.ca.key_path', $this->caDir.'/ca.key');
        config()->set('dbvault.ca.key_passphrase', null);
    }

    protected function tearDown(): void
    {
        @unlink($this->caDir.'/ca.crt');
        @unlink($this->caDir.'/ca.key');
        @rmdir($this->caDir);
        parent::tearDown();
    }

    public function test_service_reports_configured_when_ca_present(): void
    {
        $this->assertTrue(app(CertificateAuthorityService::class)->isConfigured());
    }

    public function test_service_issues_a_valid_p12_and_fingerprint(): void
    {
        $user = $this->makeUser('developer');
        $result = app(CertificateAuthorityService::class)->issueForUser($user, 'Laptop');

        $this->assertNotEmpty($result['p12']);
        $this->assertStringStartsWith('SHA256:', $result['fingerprint']);
        $this->assertStringContainsString('CN='.$user->email, $result['dn']);

        // The .p12 is a real bundle openssl can re-open with the password.
        $certs = [];
        $this->assertTrue(openssl_pkcs12_read($result['p12'], $certs, $result['password']));
        $this->assertArrayHasKey('cert', $certs);
        $this->assertArrayHasKey('pkey', $certs);
    }

    public function test_admin_can_issue_a_cert_which_enrolls_a_device(): void
    {
        $admin = $this->makeUser('admin');
        $developer = $this->makeUser('developer');

        $response = $this->actingAs($admin, 'vault')->postJson('/vault/api/devices/issue', [
            'user_id' => $developer->id,
            'label' => 'Issued laptop',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('device.user.id', $developer->id)
            ->assertJsonPath('device.is_revoked', false)
            ->assertJsonStructure(['device', 'pkcs12_base64', 'password', 'filename']);

        // A device row was persisted with the issued fingerprint.
        $this->assertSame(1, Device::where('user_id', $developer->id)->count());
        $device = Device::where('user_id', $developer->id)->first();
        $this->assertStringStartsWith('SHA256:', $device->cert_fingerprint);
    }

    public function test_index_advertises_issuance_availability(): void
    {
        $admin = $this->makeUser('admin');

        $this->actingAs($admin, 'vault')
            ->getJson('/vault/api/devices')
            ->assertOk()
            ->assertJsonPath('can_issue', true);
    }

    public function test_issue_fails_cleanly_when_no_ca_configured(): void
    {
        config()->set('dbvault.ca.cert_path', null);
        config()->set('dbvault.ca.key_path', null);

        $admin = $this->makeUser('admin');
        $developer = $this->makeUser('developer');

        $this->actingAs($admin, 'vault')->postJson('/vault/api/devices/issue', [
            'user_id' => $developer->id,
        ])->assertStatus(422);

        $this->assertSame(0, Device::count());
    }

    public function test_non_admin_cannot_issue(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')
            ->postJson('/vault/api/devices/issue', ['user_id' => $developer->id])
            ->assertStatus(403);
    }
}
