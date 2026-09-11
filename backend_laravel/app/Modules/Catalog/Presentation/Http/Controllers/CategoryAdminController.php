<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Modules\Catalog\Application\Services\CategoryService;
use App\Modules\Catalog\Presentation\Http\Requests\CategoryRequest;
use App\Modules\Catalog\Presentation\Http\Resources\CategoryResource;
use Illuminate\Http\JsonResponse;

class CategoryAdminController
{
    public function __construct(
        private readonly CategoryService $categoryService
    ) {}

    public function index(): JsonResponse
    {
        $categories = $this->categoryService->getAdminTree();

        return response()->json([
            'data' => CategoryResource::collection($categories),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $category = $this->categoryService->getById($id);

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        return response()->json([
            'data' => new CategoryResource($category),
        ]);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->validated());

        return response()->json([
            'message' => 'Category created successfully',
            'data'    => new CategoryResource($category),
        ], 201);
    }

    public function update(CategoryRequest $request, int $id): JsonResponse
    {
        $category = $this->categoryService->getById($id);

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $updated = $this->categoryService->update($category, $request->validated());

        return response()->json([
            'message' => 'Category updated successfully',
            'data'    => new CategoryResource($updated),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $category = $this->categoryService->getById($id);

        if (! $category) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        try {
            $this->categoryService->delete($category);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Cannot delete category: it has active children or assigned products.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }
}
