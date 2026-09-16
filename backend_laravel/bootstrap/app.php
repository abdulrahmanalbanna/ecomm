<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Modules\Identity\Presentation\Http\Middleware\RoleMiddleware::class,
            'permission' => \App\Modules\Identity\Presentation\Http\Middleware\PermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render unhandled exceptions as JSON for API requests.
        // Domain exceptions are mapped to HTTP responses by their respective
        // controllers (mirroring the Identity module pattern).
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request): bool => $request->is('api/*') || $request->expectsJson()
        );

        // HandleCors may not decorate an exception rendered after the
        // middleware stack. Preserve the configured allowlist so the
        // storefront can read the real JSON error instead of reporting only
        // a misleading browser CORS failure.
        $exceptions->respond(function ($response) {
            $request = request();
            $origin = $request->headers->get('Origin');
            $allowedOrigins = config('cors.allowed_origins', []);

            if ($request->is('api/*') && $origin && in_array($origin, $allowedOrigins, true)) {
                $response->headers->set('Access-Control-Allow-Origin', $origin);
                $response->headers->set('Vary', 'Origin');
                $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
                $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With, X-CSRF-TOKEN');
            }

            return $response;
        });
    })->create();
