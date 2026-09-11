<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\AttributeDefinition;
use App\Modules\Catalog\Infrastructure\Persistence\Models\AttributeOption;
use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductAttributeDefinition;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AttributeService
{
    public function listDefinitions(): Collection
    {
        return AttributeDefinition::query()
            ->with(['options'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function getDefinitionById(int $id): ?AttributeDefinition
    {
        return AttributeDefinition::query()->with('options')->find($id);
    }

    public function createDefinition(array $data): AttributeDefinition
    {
        $definition = AttributeDefinition::create([
            'name'          => $data['name'],
            'display_name'  => $data['display_name'],
            'type'          => $data['type'],
            'unit'          => $data['unit'] ?? null,
            'is_filterable' => $data['is_filterable'] ?? false,
            'is_active'     => $data['is_active'] ?? true,
            'sort_order'    => $data['sort_order'] ?? 0,
        ]);

        return $definition->fresh(['options']);
    }

    public function updateDefinition(AttributeDefinition $definition, array $data): AttributeDefinition
    {
        return DB::transaction(function () use ($definition, $data) {
            $definition->update($data);
            return $definition->fresh(['options']);
        });
    }

    public function createOption(int $attributeId, array $data): AttributeOption
    {
        $option = AttributeOption::create([
            'attribute_id' => $attributeId,
            'value'        => strtolower(trim($data['value'])),
            'display_name' => $data['display_name'] ?? $data['value'],
            'is_active'    => $data['is_active'] ?? true,
            'sort_order'   => $data['sort_order'] ?? 0,
        ]);

        return $option->fresh();
    }

    public function updateOption(AttributeOption $option, array $data): AttributeOption
    {
        return DB::transaction(function () use ($option, $data) {
            if (isset($data['value'])) {
                $data['value'] = strtolower(trim($data['value']));
            }
            $option->update($data);
            return $option->fresh();
        });
    }

    /**
     * Assign/Configure an attribute for a product.
     * Uses explicit composite key handling for product_attribute_definitions.
     */
    public function assignProductAttribute(int $productId, int $attributeId, bool $isRequired = false, int $sortOrder = 0): ProductAttributeDefinition
    {
        return DB::transaction(function () use ($productId, $attributeId, $isRequired, $sortOrder) {
            return ProductAttributeDefinition::updateOrCreate(
                [
                    'product_id'   => $productId,
                    'attribute_id' => $attributeId,
                ],
                [
                    'is_required' => $isRequired,
                    'sort_order'  => $sortOrder,
                ]
            );
        });
    }

    /**
     * Remove an attribute assignment from a product.
     * Enforces composite key lookup and relies on DB trigger trg_product_attributes_00_guard.
     */
    public function removeProductAttribute(int $productId, int $attributeId): bool
    {
        return DB::transaction(function () use ($productId, $attributeId) {
            return ProductAttributeDefinition::query()
                ->where('product_id', $productId)
                ->where('attribute_id', $attributeId)
                ->delete() > 0;
        });
    }
}
