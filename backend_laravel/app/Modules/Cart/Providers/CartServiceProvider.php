<?php

declare(strict_types=1);

namespace App\Modules\Cart\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CartServiceProvider extends ServiceProvider
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
        $routeFile = __DIR__ . '/../Presentation/Routes/api.php';

        if (! file_exists($routeFile)) {
            return;
        }

        Route::middleware('api')
            ->prefix('api/v1')
            ->group($routeFile);
    }
}
