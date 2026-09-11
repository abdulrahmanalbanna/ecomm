<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * ModuleRoutesTest
 *
 * Verifies that module routes are registered and reachable.
 *
 * No database connection required.
 */
final class ModuleRoutesTest extends TestCase
{
    /**
     * The Identity module ping route must be reachable at the expected URL.
     *
     * This validates:
     *   1. IdentityServiceProvider loaded its route file.
     *   2. The 'api' middleware group was applied.
     *   3. The 'api/v1' prefix was applied by the provider.
     *   4. The route returns the expected JSON payload.
     */
    public function test_identity_module_ping_route_is_reachable(): void
    {
        $response = $this->getJson('/api/v1/identity/ping');

        $response->assertStatus(200)
                 ->assertJsonStructure(['module', 'status', 'version'])
                 ->assertJsonFragment([
                     'module'  => 'Identity',
                     'status'  => 'ok',
                     'version' => 'v1',
                 ]);
    }

    /**
     * Accessing a non-existent API route must return 404 JSON (not HTML).
     *
     * This validates that the JSON exception handler in bootstrap/app.php
     * is active for all api/* routes.
     */
    public function test_unknown_api_route_returns_json_404(): void
    {
        $response = $this->getJson('/api/v1/this-route-does-not-exist');

        $response->assertStatus(404)
                 ->assertHeader('Content-Type', 'application/json');
    }

    /**
     * The named route 'identity.ping' must resolve to the correct URL.
     *
     * This validates the route name was registered correctly and can be
     * used for URL generation in controllers and tests.
     */
    public function test_identity_ping_route_is_named_correctly(): void
    {
        $url = route('identity.ping');

        $this->assertStringEndsWith('/api/v1/identity/ping', $url);
    }
}
