<?php
declare(strict_types=1);
namespace App\Modules\Customer\Presentation\Http\Controllers;

use App\Modules\Customer\Application\Actions\GetCustomerProfileAction;
use App\Modules\Customer\Application\Actions\UpdateCustomerProfileAction;
use App\Modules\Customer\Presentation\Http\Requests\UpdateCustomerProfileRequest;
use App\Modules\Customer\Presentation\Http\Resources\CustomerProfileResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CustomerProfileController extends Controller
{
    /**
     * GET /api/v1/customer/profile
     * Return the authenticated customer's profile, lazily creating a default.
     */
    public function show(Request $request, GetCustomerProfileAction $action): JsonResponse
    {
        $profile = $action->execute($request->user());

        return response()->json([
            'data' => new CustomerProfileResource($profile),
        ], 200);
    }

    /**
     * PUT /api/v1/customer/profile
     * Update the authenticated customer's profile.
     */
    public function update(UpdateCustomerProfileRequest $request, UpdateCustomerProfileAction $action): JsonResponse
    {
        $profile = $action->execute($request->user(), $request->toDTO());

        return response()->json([
            'data' => new CustomerProfileResource($profile),
        ], 200);
    }
}
