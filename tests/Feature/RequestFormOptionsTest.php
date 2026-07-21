<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Tests\TestCase;

class RequestFormOptionsTest extends TestCase
{
    public function test_it_returns_target_plus_allowed_databases(): void
    {
        config()->set('dbvault.target_database', 'stellaroms');
        config()->set('dbvault.allowed_databases', ['acraglide', 'lenderoms', 'stellaroms']);

        $developer = $this->makeUser('developer');

        $response = $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/form-options')
            ->assertOk();

        // Target first, de-duplicated (stellaroms appears once).
        $this->assertSame(
            ['stellaroms', 'acraglide', 'lenderoms'],
            $response->json('databases'),
        );
    }

    public function test_it_returns_configured_durations_with_labels(): void
    {
        config()->set('dbvault.available_durations', [30, 60, 120]);

        $developer = $this->makeUser('developer');

        $this->actingAs($developer, 'vault')
            ->getJson('/vault/api/requests/form-options')
            ->assertOk()
            ->assertJsonPath('durations.0', ['value' => 30, 'label' => '30 minutes'])
            ->assertJsonPath('durations.1', ['value' => 60, 'label' => '1 hour'])
            ->assertJsonPath('durations.2', ['value' => 120, 'label' => '2 hours']);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/vault/api/requests/form-options')->assertStatus(401);
    }
}
