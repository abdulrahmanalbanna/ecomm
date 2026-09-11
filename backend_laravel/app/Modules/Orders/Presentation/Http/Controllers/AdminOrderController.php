<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Controllers;

use App\Modules\Inventory\Domain\Exceptions\InsufficientInventoryException;
use App\Modules\Inventory\Domain\Exceptions\InvalidReservationException;
use App\Modules\Orders\Application\Actions\CancelAdminOrderAction;
use App\Modules\Orders\Application\Actions\GetAdminOrderAction;
use App\Modules\Orders\Application\Actions\ListAdminOrdersAction;
use App\Modules\Orders\Application\Actions\ListOrderEventsAction;
use App\Modules\Orders\Application\Actions\TransitionOrderStatusAction;
use App\Modules\Orders\Application\DTOs\OrderListFiltersData;
use App\Modules\Orders\Domain\Exceptions\InvalidStatusTransitionException;
use App\Modules\Orders\Domain\Exceptions\OrderDomainException;
use App\Modules\Orders\Domain\Exceptions\OrderNotCancellableException;
use App\Modules\Orders\Domain\Exceptions\OrderNotFoundException;
use App\Modules\Orders\Presentation\Http\Requests\CancelOrderRequest;
use App\Modules\Orders\Presentation\Http\Requests\TransitionOrderStatusRequest;
use App\Modules\Orders\Presentation\Http\Resources\OrderAdminResource;
use App\Modules\Orders\Presentation\Http\Resources\OrderEventResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin order management. RBAC via existing permissions:
 *  - orders.view    → list / detail / events
 *  - orders.process → status transitions
 *  - orders.cancel  → cancellation
 */
class AdminOrderController
{
    public function __construct(
        private readonly ListAdminOrdersAction $listAction,
        private readonly GetAdminOrderAction $getAction,
        private readonly ListOrderEventsAction $eventsAction,
        private readonly TransitionOrderStatusAction $transitionAction,
        private readonly CancelAdminOrderAction $cancelAction,
    ) {
    }

    /**
     * GET /api/v1/admin/orders
     */
    public function index(Request $request): JsonResponse
    {
        $filters = new OrderListFiltersData(
            status: $request->query('status'),
            dateFrom: $request->query('date_from'),
            dateTo: $request->query('date_to'),
            search: $request->query('search'),
            page: max(1, (int) $request->query('page', '1')),
            perPage: min(100, max(1, (int) $request->query('per_page', '15'))),
        );

        $paginator = $this->listAction->execute($filters);

        return response()->json([
            'data' => OrderAdminResource::collection($paginator->getCollection()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/v1/admin/orders/{publicId}
     */
    public function show(string $publicId): JsonResponse
    {
        try {
            $order = $this->getAction->execute($publicId);
        } catch (OrderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json(['data' => new OrderAdminResource($order)]);
    }

    /**
     * GET /api/v1/admin/orders/{publicId}/events
     */
    public function events(Request $request, string $publicId): JsonResponse
    {
        try {
            $order = $this->listAction->get($publicId);
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

    /**
     * POST /api/v1/admin/orders/{publicId}/transition
     */
    public function transition(TransitionOrderStatusRequest $request, string $publicId): JsonResponse
    {
        try {
            $order = $this->transitionAction->findForAdmin($publicId);

            $updated = $this->transitionAction->execute(
                order: $order,
                toStatus: (string) $request->input('status'),
                actorId: $request->user()->id,
                note: $request->input('note'),
                metadata: ['channel' => 'admin'],
            );
        } catch (OrderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (InvalidStatusTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (InsufficientInventoryException|InvalidReservationException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (OrderDomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order status updated successfully',
            'data'    => new OrderAdminResource($updated),
        ]);
    }

    /**
     * POST /api/v1/admin/orders/{publicId}/cancel
     */
    public function cancel(CancelOrderRequest $request, string $publicId): JsonResponse
    {
        try {
            $order = $this->cancelAction->execute(
                publicId: $publicId,
                actorId: $request->user()->id,
                reason: $request->input('reason'),
            );
        } catch (OrderNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (OrderNotCancellableException|InvalidStatusTransitionException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Order cancelled successfully',
            'data'    => new OrderAdminResource($order),
        ]);
    }
}
