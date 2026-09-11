<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Services;

use App\Modules\Catalog\Infrastructure\Persistence\Models\Category;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CategoryService
{
    /**
     * Get active category tree for public presentation.
     */
    public function getPublicTree(): Collection
    {
        return Category::query()
            ->active()
            ->root()
            ->with(['children' => function ($query) {
                $query->active()->with(['children' => function ($q) {
                    $q->active();
                }]);
            }])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get complete category tree for administrative management.
     */
    public function getAdminTree(): Collection
    {
        return Category::query()
            ->root()
            ->with(['children.children'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function getBySlug(string $slug): ?Category
    {
        return Category::query()
            ->where('slug', $slug)
            ->with(['children' => function ($q) {
                $q->active();
            }])
            ->first();
    }

    public function getById(int $id): ?Category
    {
        return Category::query()
            ->with(['children', 'parent'])
            ->find($id);
    }

    public function getAncestors(Category $category): Collection
    {
        if (! $category->path) {
            return new Collection();
        }

        return Category::query()
            ->whereRaw('path @> ?::ltree', [$category->path])
            ->where('id', '<>', $category->id)
            ->orderBy('depth', 'asc')
            ->get();
    }

    public function getSubtree(Category $category, bool $activeOnly = false): Collection
    {
        if (! $category->path) {
            return new Collection([$category]);
        }

        $query = Category::query()
            ->whereRaw('path <@ ?::ltree', [$category->path]);

        if ($activeOnly) {
            $query->active();
        }

        return $query->orderBy('depth', 'asc')->orderBy('sort_order', 'asc')->get();
    }

    public function create(array $data): Category
    {
        $category = Category::create([
            'parent_id'   => $data['parent_id'] ?? null,
            'slug'        => $data['slug'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'image_url'   => $data['image_url'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
            'sort_order'  => $data['sort_order'] ?? 0,
        ]);

        return $category->fresh();
    }

    public function update(Category $category, array $data): Category
    {
        $category->update(array_filter([
            'parent_id'   => array_key_exists('parent_id', $data) ? $data['parent_id'] : $category->parent_id,
            'slug'        => $data['slug'] ?? $category->slug,
            'name'        => $data['name'] ?? $category->name,
            'description' => array_key_exists('description', $data) ? $data['description'] : $category->description,
            'image_url'   => array_key_exists('image_url', $data) ? $data['image_url'] : $category->image_url,
            'is_active'   => array_key_exists('is_active', $data) ? $data['is_active'] : $category->is_active,
            'sort_order'  => array_key_exists('sort_order', $data) ? $data['sort_order'] : $category->sort_order,
        ], fn ($val) => $val !== null || true));

        return $category->fresh();
    }

    public function delete(Category $category): bool
    {
        return DB::transaction(function () use ($category) {
            return (bool) $category->delete();
        });
    }
}
