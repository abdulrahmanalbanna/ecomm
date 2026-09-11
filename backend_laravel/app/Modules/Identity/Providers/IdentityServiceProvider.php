<?php

declare(strict_types=1);

namespace App\Modules\Identity\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * IdentityServiceProvider
 *
 * Registers all Identity module services, routes, and bindings.
 *
 * Pattern guide for future modules:
 *   1. Copy this provider to your module's Providers/ directory.
 *   2. Rename the class to {YourModule}ServiceProvider.
 *   3. Update the namespace.
 *   4. Create your module's route file at Presentation/Routes/api.php.
 *   5. Add your module name to ModuleRegistry::MODULES.
 *
 * Dependency rules enforced here:
 *   - This provider MUST NOT import from another module's namespace.
 *   - Shared layer contracts are acceptable imports.
 *   - Domain classes MUST NOT reference Eloquent or Laravel infrastructure.
 */
final class IdentityServiceProvider extends ServiceProvider
{
    /**
     * Register Identity module bindings.
     *
     * Future: bind Identity repository contracts to implementations here.
     * Example:
     *   $this->app->bind(
     *       \App\Modules\Identity\Domain\Contracts\UserRepositoryInterface::class,
     *       \App\Modules\Identity\Infrastructure\Repositories\EloquentUserRepository::class,
     *   );
     */
    public function register(): void
    {
        // Bindings registered in future tasks.
    }

    /**
     * Bootstrap Identity module services.
     *
     * Routes are loaded here so they are registered after all bindings
     * are available in the container.
     */
    public function boot(): void
    {
        \Illuminate\Support\Facades\Auth::extend('identity_token', function ($app, $name, array $config) {
            $provider = \Illuminate\Support\Facades\Auth::createUserProvider($config['provider']);
            return new \App\Modules\Identity\Infrastructure\Authentication\Guards\IdentityTokenGuard(
                $provider,
                $app['request']
            );
        });

        $this->loadModuleRoutes();
    }

    /**
     * Load the module's API routes with appropriate prefix and middleware.
     *
     * All module routes are grouped under:
     *   - middleware: api
     *   - prefix: api/v1
     *
     * The route file itself may add further sub-prefixes (e.g. /auth, /users).
     */
    private function loadModuleRoutes(): void
    {
        $routeFile = __DIR__ . '/../Presentation/Routes/api.php';

        if (! file_exists($routeFile)) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group($routeFile);
    }
}
