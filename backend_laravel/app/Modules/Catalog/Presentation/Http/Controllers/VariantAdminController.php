<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Modules\Catalog\Application\Services\VariantService;
use App\Modules\Catalog\Presentation\Http\Requests\ProductVariantRequest;
use App\Modules\Catalog\Presentation\Http\Resources\ProductVariantAdminResource;
use Illuminate\Http\JsonResponse;

class VariantAdminController
{
    public function __construct(
        private readonly VariantService $variantService
    ) {}

    public function index(int $productId): JsonResponse
    {
        $variants = $this->variantService->getByProductId($productId);

        return response()->json([
            'data' => ProductVariantAdminResource::collection($variants),
        ]);
    }

    public function store(ProductVariantRequest $request, int $productId): JsonResponse
    {
        try {
            $variant = $this->variantService->create($productId, $request->validated());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create product variant',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product variant created successfully',
            'data'    => new ProductVariantAdminResource($variant),
        ], 201);
    }

    public function update(ProductVariantRequest $request, int $productId, int $variantId): JsonResponse
    {
        $variant = $this->variantService->getById($variantId);

        if (! $variant || $variant->product_id !== $productId) {
            return response()->json(['message' => 'Product variant not found'], 404);
        }

        try {
            $updated = $this->variantService->update($variant, $request->validated());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update product variant',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product variant updated successfully',
            'data'    => new ProductVariantAdminResource($updated),
        ]);
    }

    public function destroy(int $productId, int $variantId): JsonResponse
    {
        $variant = $this->variantService->getById($variantId);

        if (! $variant || $variant->product_id !== $productId) {
            return response()->json(['message' => 'Product variant not found'], 404);
        }

        try {
            $this->variantService->delete($variant);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete product variant',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product variant deleted successfully',
        ]);
    }
}
