<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Http\Controllers;

use App\Modules\Inventory\Application\Services\InventoryMovementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryMovementAdminController
{
    public function __construct(
        private readonly InventoryMovementService $movementService
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $filters = $request->only([
            'variant_id',
            'movement_type',
            'order_id',
            'created_by',
            'date_from',
            'date_to',
        ]);

        $paginator = $this->movementService->getAllHistory($filters, $perPage);

        return response()->json($paginator);
    }

    public function variantHistory(Request $request, int $variantId): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 15);
        $filters = $request->only([
            'movement_type',
            'order_id',
            'created_by',
            'date_from',
            'date_to',
        ]);

        $paginator = $this->movementService->getHistoryForVariant($variantId, $filters, $perPage);

        return response()->json($paginator);
    }
}
