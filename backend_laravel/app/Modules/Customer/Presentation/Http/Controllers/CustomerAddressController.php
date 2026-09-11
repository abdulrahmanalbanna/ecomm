<?php
declare(strict_types=1);
namespace App\Modules\Customer\Presentation\Http\Controllers;

use App\Modules\Customer\Application\Actions\CreateAddressAction;
use App\Modules\Customer\Application\Actions\DeleteAddressAction;
use App\Modules\Customer\Application\Actions\GetAddressAction;
use App\Modules\Customer\Application\Actions\ListAddressesAction;
use App\Modules\Customer\Application\Actions\SetDefaultAddressAction;
use App\Modules\Customer\Application\Actions\UpdateAddressAction;
use App\Modules\Customer\Domain\Exceptions\AddressNotFoundException;
use App\Modules\Customer\Presentation\Http\Requests\CreateAddressRequest;
use App\Modules\Customer\Presentation\Http\Requests\UpdateAddressRequest;
use App\Modules\Customer\Presentation\Http\Resources\CustomerAddressResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class CustomerAddressController extends Controller
{
    /**
     * GET /api/v1/customer/addresses
     * List all addresses owned by the authenticated user.
     */
    public function index(Request $request, ListAddressesAction $action): JsonResponse
    {
        $addresses = $action->execute($request->user());

        return response()->json([
            'data' => CustomerAddressResource::collection($addresses),
        ], 200);
    }

    /**
     * POST /api/v1/customer/addresses
     * Create a new address for the authenticated user.
     */
    public function store(CreateAddressRequest $request, CreateAddressAction $action): JsonResponse
    {
        $address = $action->execute($request->user(), $request->toDTO());

        return response()->json([
            'data' => new CustomerAddressResource($address),
        ], 201);
    }

    /**
     * GET /api/v1/customer/addresses/{address}
     * Retrieve a single address owned by the authenticated user.
     *
     * @throws AddressNotFoundException
     */
    public function show(Request $request, int $address, GetAddressAction $action): JsonResponse
    {
        try {
            $addressModel = $action->execute($request->user(), $address);

            return response()->json([
                'data' => new CustomerAddressResource($addressModel),
            ], 200);
        } catch (AddressNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * PUT /api/v1/customer/addresses/{address}
     * Update an existing address owned by the authenticated user.
     *
     * @throws AddressNotFoundException
     */
    public function update(UpdateAddressRequest $request, int $address, UpdateAddressAction $action): JsonResponse
    {
        try {
            $addressModel = $action->execute($request->user(), $address, $request->toDTO());

            return response()->json([
                'data' => new CustomerAddressResource($addressModel),
            ], 200);
        } catch (AddressNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * DELETE /api/v1/customer/addresses/{address}
     * Delete an address owned by the authenticated user.
     *
     * @throws AddressNotFoundException
     */
    public function destroy(Request $request, int $address, DeleteAddressAction $action): JsonResponse
    {
        try {
            $action->execute($request->user(), $address);

            return response()->json(null, 204);
        } catch (AddressNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }

    /**
     * PATCH /api/v1/customer/addresses/{address}/default
     * Mark an address as the default for the authenticated user.
     *
     * @throws AddressNotFoundException
     */
    public function setDefault(Request $request, int $address, SetDefaultAddressAction $action): JsonResponse
    {
        try {
            $addressModel = $action->execute($request->user(), $address);

            return response()->json([
                'data' => new CustomerAddressResource($addressModel),
            ], 200);
        } catch (AddressNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }
    }
}
