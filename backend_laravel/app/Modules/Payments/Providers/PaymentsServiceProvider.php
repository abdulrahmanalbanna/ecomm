<?php

declare(strict_types=1);

namespace App\Modules\Payments\Providers;

use App\Modules\Payments\Infrastructure\Gateways\PaymentGatewayRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Payments module service provider.
 *
 * - Binds the gateway registry as a singleton (one registry instance holds
 *   the Tabby/Tamara processors; tests may swap entries via register()).
 * - Loads the module routes under the api/v1 prefix (Orders pattern).
 */
class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayRegistry::class, static fn (): PaymentGatewayRegistry => new PaymentGatewayRegistry());
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
    }

    private function loadModuleRoutes(): void
    {
        $routeFile = __DIR__ . '/../Routes/api.php';

        if (! file_exists($routeFile)) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group($routeFile);
    }
}
