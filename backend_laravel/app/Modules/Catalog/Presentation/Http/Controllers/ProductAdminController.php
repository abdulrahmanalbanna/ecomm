<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Modules\Catalog\Application\Services\ProductService;
use App\Modules\Catalog\Presentation\Http\Requests\ProductRequest;
use App\Modules\Catalog\Presentation\Http\Resources\ProductAdminResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductAdminController
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status',
            'is_active',
            'category_id',
            'search',
        ]);

        $perPage = (int) $request->input('per_page', 15);
        $products = $this->productService->getAdminProducts($filters, $perPage);

        return response()->json([
            'data' => ProductAdminResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $product = $this->productService->getAdminProduct($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json([
            'data' => new ProductAdminResource($product),
        ]);
    }

    public function store(ProductRequest $request): JsonResponse
    {
        $product = $this->productService->create($request->validated());

        return response()->json([
            'message' => 'Product created successfully',
            'data'    => new ProductAdminResource($product),
        ], 201);
    }

    public function update(ProductRequest $request, string $id): JsonResponse
    {
        $product = $this->productService->getAdminProduct($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        $updated = $this->productService->update($product, $request->validated());

        return response()->json([
            'message' => 'Product updated successfully',
            'data'    => new ProductAdminResource($updated),
        ]);
    }

    public function publish(string $id): JsonResponse
    {
        $product = $this->productService->getAdminProduct($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        try {
            $published = $this->productService->publish($product);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to publish product',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product published successfully',
            'data'    => new ProductAdminResource($published),
        ]);
    }

    public function archive(string $id): JsonResponse
    {
        $product = $this->productService->getAdminProduct($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        try {
            $archived = $this->productService->archive($product);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to archive product',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product archived successfully',
            'data'    => new ProductAdminResource($archived),
        ]);
    }

    public function destroy(string $id): JsonResponse
    {
        $product = $this->productService->getAdminProduct($id);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        try {
            $this->productService->delete($product);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to delete product',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product deleted successfully',
        ]);
    }
}
