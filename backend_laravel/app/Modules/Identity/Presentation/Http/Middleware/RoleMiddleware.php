<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Middleware;

use App\Modules\Identity\Application\Services\AuthorizationService;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function __construct(
        protected AuthorizationService $authorizationService
    ) {}

    /**
     * Handle an incoming request.
     *
     * Middleware syntax examples:
     *   - middleware('role:admin')
     *   - middleware('role:staff,admin')
     *   - middleware('role:staff', 'admin')
     *
     * Returns 401 if unauthenticated, 403 if role mismatch.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = Auth::guard('api')->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Support both variadic arguments and comma-separated string format
        $parsedRoles = [];
        foreach ($roles as $roleParam) {
            foreach (explode(',', $roleParam) as $role) {
                $trimmed = trim($role);
                if ($trimmed !== '') {
                    $parsedRoles[] = $trimmed;
                }
            }
        }

        if (! $this->authorizationService->hasRole($user, $parsedRoles)) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        return $next($request);
    }
}
