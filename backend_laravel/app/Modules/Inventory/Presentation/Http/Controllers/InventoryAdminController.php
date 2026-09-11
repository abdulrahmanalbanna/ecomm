<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Http\Controllers;

use App\Modules\Inventory\Application\DTOs\InventorySettingsData;
use App\Modules\Inventory\Application\DTOs\StockAdjustmentData;
use App\Modules\Inventory\Application\DTOs\StockDeductionData;
use App\Modules\Inventory\Application\DTOs\StockReceiveData;
use App\Modules\Inventory\Application\DTOs\StockReleaseData;
use App\Modules\Inventory\Application\DTOs\StockReservationData;
use App\Modules\Inventory\Application\DTOs\StockReturnData;
use App\Modules\Inventory\Application\Services\InventoryService;
use App\Modules\Inventory\Domain\Exceptions\DuplicateReservationException;
use App\Modules\Inventory\Domain\Exceptions\InsufficientInventoryException;
use App\Modules\Inventory\Domain\Exceptions\InvalidInventoryAdjustmentException;
use App\Modules\Inventory\Domain\Exceptions\InvalidReservationException;
use App\Modules\Inventory\Domain\Exceptions\InventoryNotFoundException;
use App\Modules\Inventory\Domain\Exceptions\ReservationAlreadyConvertedException;
use App\Modules\Inventory\Domain\Exceptions\ReservationAlreadyReleasedException;
use App\Modules\Inventory\Presentation\Http\Requests\AdjustStockRequest;
use App\Modules\Inventory\Presentation\Http\Requests\DeductStockRequest;
use App\Modules\Inventory\Presentation\Http\Requests\ReceiveStockRequest;
use App\Modules\Inventory\Presentation\Http\Requests\ReleaseStockRequest;
use App\Modules\Inventory\Presentation\Http\Requests\ReserveStockRequest;
use App\Modules\Inventory\Presentation\Http\Requests\ReturnStockRequest;
use App\Modules\Inventory\Presentation\Http\Requests\UpdateInventorySettingsRequest;
use App\Modules\Inventory\Presentation\Http\Resources\InventoryAdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

class InventoryAdminController
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $filters = $request->only(['search', 'in_stock']);

        $paginator = $this->inventoryService->listInventory($filters, $perPage);

        return response()->json($paginator);
    }

    public function show(int $variantId): JsonResponse
    {
        try {
            $inventory = $this->inventoryService->getInventory($variantId);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        return response()->json([
            'data' => new InventoryAdminResource($inventory),
        ]);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $paginator = $this->inventoryService->getLowStock($perPage);

        return response()->json($paginator);
    }

    public function updateSettings(UpdateInventorySettingsRequest $request, int $variantId): JsonResponse
    {
        $dto = new InventorySettingsData(
            reorderPoint: $request->has('reorder_point') ? (int) $request->input('reorder_point') : null,
            reorderQuantity: $request->has('reorder_quantity') ? (int) $request->input('reorder_quantity') : null,
            allowBackorder: $request->has('allow_backorder') ? (bool) $request->input('allow_backorder') : null,
        );

        try {
            $inventory = $this->inventoryService->updateSettings($variantId, $dto);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Inventory settings updated successfully',
            'data'    => new InventoryAdminResource($inventory),
        ]);
    }

    public function receive(ReceiveStockRequest $request, int $variantId): JsonResponse
    {
        $dto = new StockReceiveData(
            variantId: $variantId,
            quantity: (int) $request->input('quantity'),
            note: $request->input('note'),
            createdBy: auth()->id() ? (int) auth()->id() : null,
        );

        try {
            $inventory = $this->inventoryService->receiveStock($dto);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Stock received successfully',
            'data'    => new InventoryAdminResource($inventory),
        ]);
    }

    public function adjust(AdjustStockRequest $request, int $variantId): JsonResponse
    {
        $dto = new StockAdjustmentData(
            variantId: $variantId,
            quantityDelta: (int) $request->input('quantity_delta'),
            note: $request->input('note'),
            createdBy: auth()->id() ? (int) auth()->id() : null,
        );

        try {
            $inventory = $this->inventoryService->adjustStock($dto);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (InvalidInventoryAdjustmentException|InsufficientInventoryException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Stock adjusted successfully',
            'data'    => new InventoryAdminResource($inventory),
        ]);
    }

    public function reserve(ReserveStockRequest $request, int $variantId): JsonResponse
    {
        $dto = new StockReservationData(
            variantId: $variantId,
            quantity: (int) $request->input('quantity'),
            createdBy: auth()->id() ? (int) auth()->id() : null,
            orderId: $request->has('order_id') ? (int) $request->input('order_id') : null,
        );

        try {
            $this->inventoryService->reserveStock($dto);
            $inventory = $this->inventoryService->getInventory($variantId);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (DuplicateReservationException|InsufficientInventoryException|InvalidReservationException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Stock reserved successfully',
            'data'    => new InventoryAdminResource($inventory),
        ]);
    }

    public function release(ReleaseStockRequest $request, int $variantId): JsonResponse
    {
        $dto = new StockReleaseData(
            variantId: $variantId,
            quantity: (int) ($request->input('quantity') ?? 0),
            createdBy: auth()->id() ? (int) auth()->id() : null,
            orderId: $request->has('order_id') ? (int) $request->input('order_id') : null,
            reservationId: $request->has('reservation_id') ? (int) $request->input('reservation_id') : null,
        );

        try {
            $inventory = $this->inventoryService->releaseStock($dto);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (InsufficientInventoryException|InvalidReservationException|ReservationAlreadyReleasedException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Reserved stock released successfully',
            'data'    => new InventoryAdminResource($inventory),
        ]);
    }

    public function deduct(DeductStockRequest $request, int $variantId): JsonResponse
    {
        $dto = new StockDeductionData(
            variantId: $variantId,
            quantity: (int) ($request->input('quantity') ?? 0),
            createdBy: auth()->id() ? (int) auth()->id() : null,
            orderId: $request->has('order_id') ? (int) $request->input('order_id') : null,
            reservationId: $request->has('reservation_id') ? (int) $request->input('reservation_id') : null,
        );

        try {
            $inventory = $this->inventoryService->deductStock($dto);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (ReservationAlreadyConvertedException|InsufficientInventoryException|InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Stock deducted successfully',
            'data'    => new InventoryAdminResource($inventory),
        ]);
    }

    public function return(ReturnStockRequest $request, int $variantId): JsonResponse
    {
        $dto = new StockReturnData(
            variantId: $variantId,
            quantity: (int) $request->input('quantity'),
            note: $request->input('note'),
            createdBy: auth()->id() ? (int) auth()->id() : null,
            orderId: $request->has('order_id') ? (int) $request->input('order_id') : null,
        );

        try {
            $inventory = $this->inventoryService->returnStock($dto);
        } catch (InventoryNotFoundException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Stock returned successfully',
            'data'    => new InventoryAdminResource($inventory),
        ]);
    }
}
