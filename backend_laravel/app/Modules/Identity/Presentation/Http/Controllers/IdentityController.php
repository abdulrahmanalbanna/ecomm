<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Controllers;

use App\Modules\Identity\Application\Actions\GetAuthenticatedUserAction;
use App\Modules\Identity\Application\Actions\LoginAction;
use App\Modules\Identity\Application\Actions\LogoutAction;
use App\Modules\Identity\Domain\Exceptions\AccountInactiveException;
use App\Modules\Identity\Domain\Exceptions\InvalidCredentialsException;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Modules\Identity\Presentation\Http\Requests\LoginRequest;
use App\Modules\Identity\Presentation\Http\Resources\IdentityResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class IdentityController extends Controller
{
    /**
     * Authenticate user credentials and return raw token + safe identity context.
     */
    public function login(LoginRequest $request, LoginAction $action): JsonResponse
    {
        try {
            $authOutput = $action->execute($request->toDTO());

            return response()->json([
                'data' => [
                    'token' => $authOutput->token,
                    'token_type' => $authOutput->tokenType,
                    'expires_at' => $authOutput->expiresAt->format(\DateTimeInterface::ATOM),
                    'user' => new IdentityResource($authOutput->user),
                ],
            ], 200);
        } catch (InvalidCredentialsException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 401);
        } catch (AccountInactiveException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 403);
        }
    }

    /**
     * Revoke the current session token.
     */
    public function logout(LogoutAction $action): JsonResponse
    {
        $guard = Auth::guard('api');
        $action->execute($guard);

        return response()->json([
            'message' => 'Successfully logged out.',
        ], 200);
    }

    /**
     * Return safe authenticated user profile and identity.
     */
    public function me(GetAuthenticatedUserAction $action): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $resolvedUser = $action->execute($user);

        return response()->json([
            'data' => new IdentityResource($resolvedUser),
        ], 200);
    }

    /**
     * Return user's resolved role and permission summary for authorization verification.
     */
    public function authorizationCheck(\App\Modules\Identity\Application\Services\AuthorizationService $service): JsonResponse
    {
        /** @var User $user */
        $user = Auth::guard('api')->user();

        if (! $user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        $role = $service->getUserRoleName($user);
        $permissions = $service->getUserPermissions($user);

        return response()->json([
            'data' => [
                'public_id' => $user->public_id,
                'role' => $role,
                'permissions' => $permissions,
            ],
        ], 200);
    }
}

