<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VariantService
{
    public function getByProductId(int $productId, bool $activeOnly = false): Collection
    {
        $query = ProductVariant::query()->where('product_id', $productId);
        if ($activeOnly) {
            $query->active();
        }

        return $query->get();
    }

    public function getById(int $id): ?ProductVariant
    {
        return ProductVariant::find($id);
    }

    public function create(int $productId, array $data): ProductVariant
    {
        return DB::transaction(function () use ($productId, $data) {
            $variant = ProductVariant::create([
                'product_id'       => $productId,
                'sku'              => strtoupper(trim($data['sku'])),
                'name'             => $data['name'] ?? null,
                'price'            => $data['price'],
                'compare_at_price' => $data['compare_at_price'] ?? null,
                'cost_price'       => $data['cost_price'] ?? null,
                'weight_grams'     => $data['weight_grams'] ?? null,
                'dimensions'       => $data['dimensions'] ?? null,
                'attributes'       => $data['attributes'] ?? [],
                'media'            => $data['media'] ?? [],
                'is_active'        => $data['is_active'] ?? true,
            ]);

            return $variant->fresh();
        });
    }

    public function update(ProductVariant $variant, array $data): ProductVariant
    {
        return DB::transaction(function () use ($variant, $data) {
            if (isset($data['sku'])) {
                $data['sku'] = strtoupper(trim($data['sku']));
            }
            $variant->update($data);

            return $variant->fresh();
        });
    }

    public function delete(ProductVariant $variant): bool
    {
        return DB::transaction(function () use ($variant) {
            $variant->is_active = false;
            $variant->save();

            return (bool) $variant->delete();
        });
    }
}
