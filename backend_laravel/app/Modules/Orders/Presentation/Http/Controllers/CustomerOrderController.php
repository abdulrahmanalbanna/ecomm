<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Controllers;

use App\Modules\Inventory\Domain\Exceptions\InsufficientInventoryException;
use App\Modules\Inventory\Domain\Exceptions\InventoryNotFoundException;
use App\Modules\Orders\Application\Actions\CancelCustomerOrderAction;
use App\Modules\Orders\Application\Actions\CheckoutAction;
use App\Modules\Orders\Application\Actions\GetCustomerOrderAction;
use App\Modules\Orders\Application\Actions\ListCustomerOrdersAction;
use App\Modules\Orders\Application\Actions\ListOrderEventsAction;
use App\Modules\Orders\Application\DTOs\CheckoutData;
use App\Modules\Orders\Application\DTOs\OrderListFiltersData;
use App\Modules\Orders\Domain\Exceptions\AddressNotOwnedForCheckoutException;
use App\Modules\Orders\Domain\Exceptions\CheckoutItemNotSellableException;
use App\Modules\Orders\Domain\Exceptions\EmptyCartCheckoutException;
use App\Modules\Orders\Domain\Exceptions\OrderDomainException;
use App\Modules\Orders\Domain\Exceptions\OrderNotCancellableException;
use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Presentation\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Presentation\Http\Requests\CheckoutRequest;
use App\Modules\Orders\Presentation\Http\Resources\OrderCustomerResource;
use App\Modules\Orders\Presentation\Http\Resources\OrderEventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customer order endpoints. All access is scoped to the authenticated user
 * at the application layer (RLS is not part of this baseline), so another
 * customer's order resolves to 404 rather than 403 (no existence leakage).
 */
class CustomerOrderController
{
    public function __construct(
        private readonly CheckoutAction $checkoutAction,
        private readonly ListCustomerOrdersAction $listAction,
        private readonly GetCustomerOrderAction $getAction,
        private readonly ListOrderEventsAction $eventsAction,
        private readonly CancelCustomerOrderAction $cancelAction,
    ) {
    }

    /**
     * POST /api/v1/customer/orders/checkout
     */
    public function checkout(CheckoutRequest $request): JsonResponse
    {
        $user = $request->user();

        $data = new CheckoutData(
            user: $user,
            shippingAddressId: (int) $request->input('shipping_address_id'),
            billingAddressId: $request->input('billing_address_id') !== null
                ? (int) $request->input('billing_address_id')
                : null,
            notes: $request->input('notes'),
            ipAddress: $request->ip(),
        );

        try {
            $order = $this->checkoutAction->execute($data);
        } catch (EmptyCartCheckoutException|CheckoutItemNotSellableException|AddressNotOwnedForCheckoutException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InsufficientInventoryException|InventoryNotFoundException $e) {
            return response()->json([
                'message' => 'One or more items in your cart no longer have sufficient stock.',
                'details' => $e->getMessage(),
            ], 409);
        } catch (OrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order placed successfully',
            'data'    => new OrderCustomerResource($order),
        ], 201);
    }

    /**
     * GET /api/v1/customer/orders
     */
    public function index(Request $request): JsonResponse
    {
        $filters = new OrderListFiltersData(
            status: $request->query('status'),
            dateFrom: $request->query('date_from'),
            dateTo: $request->query('date_to'),
            page: max(1, (int) $request->query('page', '1')),
            perPage: min(100, max(1, (int) $request->query('per_page', '15'))),
        );

        $paginator = $this->listAction->execute($request->user(), $filters);

        return response()->json([
            'data' => OrderCustomerResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/customer/orders/{publicId}
     */
    public function show(Request $request, string $publicId): JsonResponse
    {
        try {
            $order = $this->getAction->execute($request->user(), $publicId);
        } catch (OrderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['data' => new OrderCustomerResource($order)]);
    }

    /**
     * POST /api/v1/customer/orders/{publicId}/cancel
     */
    public function cancel(CancelOrderRequest $request, string $publicId): JsonResponse
    {
        try {
            $order = $this->cancelAction->execute(
                user: $request->user(),
                publicId: $publicId,
                reason: $request->input('reason'),
            );
        } catch (OrderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (OrderNotCancellableException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order cancelled successfully',
            'data'    => new OrderCustomerResource($order),
        ]);
    }

    /**
     * GET /api/v1/customer/orders/{publicId}/events
     */
    public function events(Request $request, string $publicId): JsonResponse
    {
        try {
            $order = $this->listAction->get($request->user(), $publicId);
        } catch (OrderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $events = $this->eventsAction->execute($order);

        return response()->json([
            'data' => array_map(
                static fn ($event): array => (new OrderEventResource($event))->toArray($request),
                $events
            ),
        ]);
    }
}
