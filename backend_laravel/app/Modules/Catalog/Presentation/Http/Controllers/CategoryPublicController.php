<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Modules\Catalog\Application\Services\CategoryService;
use App\Modules\Catalog\Presentation\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;

class CategoryPublicController
{
    public function __construct(
        private readonly CategoryService $categoryService
    ) {}

    public function index(): JsonResponse
    {
        $categories = $this->categoryService->getPublicTree();

        return response()->json([
            'data' => CategoryResource::collection($categories),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $category = $this->categoryService->getBySlug($slug);

        if (! $category || ! $category->is_active) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json([
            'data' => new CategoryResource($category),
        ]);
    }
}
