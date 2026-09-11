<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use App\Modules\Catalog\Infrastructure\Persistence\Models\Product;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProductService
{
    /**
     * Get paginated customer-visible products.
     */
    public function getPublicProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()
            ->customerVisible()
            ->with(['category', 'variants' => function ($q) {
                $q->active();
            }]);

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        } elseif (! empty($filters['category_slug'])) {
            $category = Category::where('slug', $filters['category_slug'])->first();
            if ($category) {
                $query->where('category_id', $category->id);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        if (! empty($filters['featured'])) {
            $query->featured();
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        $sort = $filters['sort'] ?? 'created_at_desc';
        match ($sort) {
            'name_asc'        => $query->orderBy('name', 'asc'),
            'name_desc'       => $query->orderBy('name', 'desc'),
            'created_at_asc'  => $query->orderBy('created_at', 'asc'),
            default           => $query->orderBy('created_at', 'desc'),
        };

        return $query->paginate($perPage);
    }

    /**
     * Deterministic public lookup by public_id (UUID) or slug.
     */
    public function getPublicProductByIdentity(string $identity): ?Product
    {
        $query = Product::query()
            ->customerVisible()
            ->with([
                'category',
                'attributeDefinitions.options' => function ($q) {
                    $q->active();
                },
                'variants' => function ($q) {
                    $q->active();
                },
            ]);

        if (Str::isUuid($identity)) {
            return $query->where('public_id', $identity)->first();
        }

        return $query->where('slug', $identity)->first();
    }

    /**
     * Admin product lookup by integer primary key or public_id.
     */
    public function getAdminProduct(string|int $id): ?Product
    {
        $query = Product::query()
            ->with([
                'category',
                'attributeDefinitions.options',
                'variants',
            ]);

        if (is_numeric($id)) {
            return $query->find((int) $id);
        }

        if (Str::isUuid((string) $id)) {
            return $query->where('public_id', $id)->first();
        }

        return $query->where('slug', (string) $id)->first();
    }

    /**
     * Admin paginated product listing.
     */
    public function getAdminProducts(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Product::query()
            ->with(['category', 'variants']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['category_id'])) {
            $query->where('category_id', (int) $filters['category_id']);
        }

        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    public function create(array $data): Product
    {
        return DB::transaction(function () use ($data) {
            $product = Product::create([
                'category_id'       => $data['category_id'],
                'slug'              => $data['slug'],
                'name'              => $data['name'],
                'description'       => $data['description'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'brand'             => $data['brand'] ?? null,
                'tags'              => $data['tags'] ?? [],
                'media'             => $data['media'] ?? [],
                'specifications'    => $data['specifications'] ?? [],
                'is_active'         => $data['is_active'] ?? true,
                'is_featured'       => $data['is_featured'] ?? false,
                'status'            => $data['status'] ?? 'draft',
                'seo_title'         => $data['seo_title'] ?? null,
                'seo_description'   => $data['seo_description'] ?? null,
            ]);

            return $product->fresh();
        });
    }

    public function update(Product $product, array $data): Product
    {
        return DB::transaction(function () use ($product, $data) {
            $product->update($data);
            return $product->fresh();
        });
    }

    public function publish(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $product->status = 'published';
            $product->is_active = true;
            $product->save();

            return $product->fresh();
        });
    }

    public function archive(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $product->status = 'archived';
            $product->save();

            return $product->fresh();
        });
    }

    /**
     * Soft delete product obeying PostgreSQL constraints.
     */
    public function delete(Product $product): bool
    {
        return DB::transaction(function () use ($product) {
            if ($product->status === 'published') {
                $product->status = 'archived';
            }
            $product->is_active = false;
            $product->save();

            return (bool) $product->delete();
        });
    }
}
