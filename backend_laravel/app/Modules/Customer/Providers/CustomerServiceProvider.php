<?php

declare(strict_types=1);

namespace App\Modules\Customer\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class CustomerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadModuleRoutes();
    }

    private function loadModuleRoutes(): void
    {
        $routeFile = __DIR__ . '/../Routes/api.php';

        if (file_exists($routeFile)) {
            Route::middleware('api')
                ->prefix('api/v1')
                ->group($routeFile);
        }
    }
}
