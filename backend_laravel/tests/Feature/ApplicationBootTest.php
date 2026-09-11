<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\ModuleRegistry;
use Tests\TestCase;

/**
 * ApplicationBootTest
 *
 * Verifies that the Laravel application boots correctly with the
 * modular architecture in place. No database connection required.
 */
final class ApplicationBootTest extends TestCase
{
    /**
     * The application must boot without throwing any exceptions.
     *
     * This test catches misconfigured service providers, bad autoload
     * entries, or missing class references that would prevent startup.
     */
    public function test_application_boots_successfully(): void
    {
        $this->assertTrue(true, 'Application booted — if this line is reached, boot succeeded.');
    }

    /**
     * The /up health endpoint must return 200.
     *
     * Laravel 11+ registers /up automatically when health: '/up' is
     * set in bootstrap/app.php. This validates the framework is serving.
     */
    public function test_health_endpoint_returns_200(): void
    {
        $response = $this->get('/up');

        $response->assertStatus(200);
    }

    /**
     * The /api/health route defined in routes/api.php must return 200
     * with the correct JSON structure.
     */
    public function test_api_health_endpoint_returns_json(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
                 ->assertJsonStructure(['status', 'application', 'environment', 'timestamp'])
                 ->assertJsonFragment(['status' => 'ok']);
    }

    /**
     * The ModuleRegistry must be resolvable from the service container.
     */
    public function test_module_registry_is_bound_in_container(): void
    {
        $registry = $this->app->make(ModuleRegistry::class);

        $this->assertInstanceOf(ModuleRegistry::class, $registry);
    }
}
