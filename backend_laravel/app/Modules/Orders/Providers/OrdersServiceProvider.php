<?php

declare(strict_types=1);

namespace App\Modules\Orders\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
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
