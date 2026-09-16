<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Modules\Catalog\Application\Services\ProductService;
use App\Modules\Catalog\Presentation\Http\Resources\ProductPublicResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductPublicController
{
    public function __construct(
        private readonly ProductService $productService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'category_id',
            'category_slug',
            'featured',
            'search',
            'sort',
        ]);
        $perPage = min(100, max(1, (int) $request->input('per_page', 15)));
        $products = $this->productService->getPublicProducts($filters, $perPage);
        return response()->json([
            'data' => ProductPublicResource::collection($products->items()),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page'    => $products->lastPage(),
                'per_page'     => $products->perPage(),
                'total'        => $products->total(),
            ],
        ]);
    }

    public function show(string $publicIdOrSlug): JsonResponse
    {
        $product = $this->productService->getPublicProductByIdentity($publicIdOrSlug);

        if (! $product) {
            return response()->json(['message' => 'Product not found'], 404);
        }

        return response()->json([
            'data' => new ProductPublicResource($product),
        ]);
    }
}
