<?php

declare(strict_types=1);

namespace App\Providers;

use App\Modules\ModuleRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * ModuleServiceProvider
 *
 * The single entry-point for the modular architecture.
 * Registered in bootstrap/providers.php.
 *
 * Responsibilities:
 *   - Binds ModuleRegistry as a singleton in the container.
 *   - Delegates register() and boot() calls to each module's own ServiceProvider.
 *
 * This class should remain thin. Business-specific registrations
 * belong in each module's own ServiceProvider.
 */
final class ModuleServiceProvider extends ServiceProvider
{
    /**
     * Register module service providers.
     *
     * Laravel calls register() on all providers before calling boot() on any.
     * That sequence is preserved here: we register child providers, which
     * causes the framework to call their register() immediately, then batch
     * all boot() calls afterward.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class);

        $registry = $this->app->make(ModuleRegistry::class);

        foreach ($registry->discover() as $providerClass) {
            $this->app->register($providerClass);
        }
    }

    /**
     * Boot is intentionally empty here.
     *
     * Each module's ServiceProvider handles its own boot() independently.
     * Laravel calls boot() on all registered providers after all register()
     * calls complete, so module providers have full container access.
     */
    public function boot(): void {}
}
