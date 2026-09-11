<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Controllers;

use App\Modules\Catalog\Application\Services\AttributeService;
use App\Modules\Catalog\Infrastructure\Persistence\Models\AttributeOption;
use App\Modules\Catalog\Presentation\Http\Requests\AttributeDefinitionRequest;
use App\Modules\Catalog\Presentation\Http\Requests\AttributeOptionRequest;
use App\Modules\Catalog\Presentation\Http\Requests\ProductAttributeRequest;
use App\Modules\Catalog\Presentation\Http\Resources\AttributeDefinitionResource;
use App\Modules\Catalog\Presentation\Http\Resources\AttributeOptionResource;
use Illuminate\Http\JsonResponse;

class AttributeAdminController
{
    public function __construct(
        private readonly AttributeService $attributeService
    ) {}

    public function index(): JsonResponse
    {
        $attributes = $this->attributeService->listDefinitions();

        return response()->json([
            'data' => AttributeDefinitionResource::collection($attributes),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $attribute = $this->attributeService->getDefinitionById($id);

        if (! $attribute) {
            return response()->json(['message' => 'Attribute definition not found'], 404);
        }

        return response()->json([
            'data' => new AttributeDefinitionResource($attribute),
        ]);
    }

    public function store(AttributeDefinitionRequest $request): JsonResponse
    {
        $attribute = $this->attributeService->createDefinition($request->validated());

        return response()->json([
            'message' => 'Attribute definition created successfully',
            'data'    => new AttributeDefinitionResource($attribute),
        ], 201);
    }

    public function update(AttributeDefinitionRequest $request, int $id): JsonResponse
    {
        $attribute = $this->attributeService->getDefinitionById($id);

        if (! $attribute) {
            return response()->json(['message' => 'Attribute definition not found'], 404);
        }

        try {
            $updated = $this->attributeService->updateDefinition($attribute, $request->validated());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update attribute definition',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Attribute definition updated successfully',
            'data'    => new AttributeDefinitionResource($updated),
        ]);
    }

    public function storeOption(AttributeOptionRequest $request, int $attributeId): JsonResponse
    {
        try {
            $option = $this->attributeService->createOption($attributeId, $request->validated());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create attribute option',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Attribute option created successfully',
            'data'    => new AttributeOptionResource($option),
        ], 201);
    }

    public function updateOption(AttributeOptionRequest $request, int $attributeId, int $optionId): JsonResponse
    {
        $option = AttributeOption::where('attribute_id', $attributeId)->find($optionId);

        if (! $option) {
            return response()->json(['message' => 'Attribute option not found'], 404);
        }

        try {
            $updated = $this->attributeService->updateOption($option, $request->validated());
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to update attribute option',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Attribute option updated successfully',
            'data'    => new AttributeOptionResource($updated),
        ]);
    }

    public function assignProductAttribute(ProductAttributeRequest $request, int $productId): JsonResponse
    {
        $data = $request->validated();

        try {
            $assignment = $this->attributeService->assignProductAttribute(
                $productId,
                (int) $data['attribute_id'],
                (bool) ($data['is_required'] ?? false),
                (int) ($data['sort_order'] ?? 0)
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to assign attribute to product',
                'error'   => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => 'Product attribute configured successfully',
            'data'    => [
                'product_id'   => $assignment->product_id,
                'attribute_id' => $assignment->attribute_id,
                'is_required'  => $assignment->is_required,
                'sort_order'   => $assignment->sort_order,
            ],
        ], 200);
    }

    public function removeProductAttribute(int $productId, int $attributeId): JsonResponse
    {
        try {
            $removed = $this->attributeService->removeProductAttribute($productId, $attributeId);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Cannot remove product attribute: existing variants use it.',
                'error'   => $e->getMessage(),
            ], 422);
        }

        if (! $removed) {
            return response()->json(['message' => 'Product attribute configuration not found'], 404);
        }

        return response()->json([
            'message' => 'Product attribute assignment removed successfully',
        ]);
    }
}
