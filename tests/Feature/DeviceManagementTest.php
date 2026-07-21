<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Models\Device;
use DbVault\Tests\TestCase;

class DeviceManagementTest extends TestCase
{
    public function test_admin_can_enroll_a_device_for_a_user(): void
    {
        $admin = $this->makeUser('admin');
        $developer = $this->makeUser('developer');

        $response = $this->actingAs($admin, 'vault')->postJson('/vault/api/devices', [
            'user_id' => $developer->id,
            'cert_fingerprint' => 'SHA256:aa:bb:cc',
            'cert_dn' => 'CN=dev,OU=eng,O=stellar',
            'label' => "Dev's laptop",
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('device.user.id', $developer->id)
            ->assertJsonPath('device.is_revoked', false);

        $this->assertSame(1, Device::where('cert_fingerprint', 'SHA256:aa:bb:cc')
            ->where('user_id', $developer->id)
            ->count());
    }

    public function test_duplicate_fingerprint_is_rejected(): void
    {
        $admin = $this->makeUser('admin');
        $developer = $this->makeUser('developer');

        Device::create([
            'user_id' => $developer->id,
            'cert_fingerprint' => 'dupe-fp',
            'cert_dn' => 'CN=x',
            'enrolled_at' => now(),
        ]);

        $this->actingAs($admin, 'vault')->postJson('/vault/api/devices', [
            'user_id' => $developer->id,
            'cert_fingerprint' => 'dupe-fp',
            'cert_dn' => 'CN=y',
        ])->assertStatus(422)->assertJsonValidationErrors('cert_fingerprint');
    }

    public function test_admin_can_revoke_and_reactivate_a_device(): void
    {
        $admin = $this->makeUser('admin');
        $device = Device::create([
            'user_id' => $admin->id,
            'cert_fingerprint' => 'fp-1',
            'cert_dn' => 'CN=a',
            'enrolled_at' => now(),
        ]);

        $this->actingAs($admin, 'vault')
            ->patchJson("/vault/api/devices/{$device->id}", ['revoked' => true])
            ->assertOk()->assertJsonPath('device.is_revoked', true);
        $this->assertNotNull($device->fresh()->revoked_at);

        $this->actingAs($admin, 'vault')
            ->patchJson("/vault/api/devices/{$device->id}", ['revoked' => false])
            ->assertOk()->assertJsonPath('device.is_revoked', false);
        $this->assertNull($device->fresh()->revoked_at);
    }

    public function test_non_admin_cannot_manage_devices(): void
    {
        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')->getJson('/vault/api/devices')->assertStatus(403);
    }

    public function test_device_management_requires_authentication(): void
    {
        $this->getJson('/vault/api/devices')->assertStatus(401);
    }
}
