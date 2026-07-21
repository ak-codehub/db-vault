<?php

declare(strict_types=1);

namespace DbVault\Tests\Feature;

use DbVault\Tests\TestCase;

class ApiJsonErrorsTest extends TestCase
{
    public function test_validation_failure_returns_422_json_not_a_redirect(): void
    {
        $developer = $this->makeUser('developer');

        $response = $this->actingAs($developer, 'vault')->postJson('/vault/api/requests', [
            'target_database' => 'appdb',
            'duration_minutes' => 60,
            'reason' => '', // required -> must fail
            'grants' => [['table' => 't', 'privileges' => ['select']]],
        ]);

        // Must be a JSON 422 with a readable field error — never a 302
        // redirect that the SPA cannot parse.
        $response->assertStatus(422)
            ->assertHeader('content-type', 'application/json')
            ->assertJsonValidationErrors('reason');
    }

    public function test_unauthenticated_api_request_returns_401_json(): void
    {
        $this->getJson('/vault/api/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_the_package_registers_json_renderables_for_vault_api(): void
    {
        // Simulate the common host config that scopes JSON rendering to its
        // OWN api/* urls, which would otherwise redirect vault validation
        // errors. The package's renderable must still win for vault/api/*.
        $handler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);

        $request = \Illuminate\Http\Request::create('http://localhost/vault/api/requests', 'POST');
        $request->headers->set('Accept', 'text/html'); // deliberately NOT json

        $exception = \Illuminate\Validation\ValidationException::withMessages([
            'reason' => ['The reason field is required.'],
        ]);

        $response = $handler->render($request, $exception);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('application/json', (string) $response->headers->get('content-type'));
    }
}
