<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Category Model
 *
 * Repesents hierarchical categories using PostgreSQL ltree extension.
 * path and depth are managed exclusively by PostgreSQL triggers.
 */
class Category extends Model
{
    protected $table = 'categories';

    protected $fillable = [
        'parent_id',
        'slug',
        'name',
        'description',
        'image_url',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
        'depth'      => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeRoot(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /**
     * PostgreSQL ltree subtree query (<@).
     */
    public function scopeSubtree(Builder $query, string $path): Builder
    {
        return $query->whereRaw('path <@ ?::ltree', [$path]);
    }

    /**
     * PostgreSQL ltree ancestor query (@>).
     */
    public function scopeAncestors(Builder $query, string $path): Builder
    {
        return $query->whereRaw('path @> ?::ltree', [$path])
            ->where('id', '<>', $this->id ?? 0);
    }
}
