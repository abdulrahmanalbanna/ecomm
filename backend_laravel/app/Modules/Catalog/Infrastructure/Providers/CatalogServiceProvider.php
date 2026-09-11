<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Service bindings if needed in future tasks
    }

    public function boot(): void
    {
        $this->loadModuleRoutes();
    }

    private function loadModuleRoutes(): void
    {
        $routeFile = __DIR__ . '/../../Presentation/Routes/api.php';

        if (! file_exists($routeFile)) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group($routeFile);
    }
}
