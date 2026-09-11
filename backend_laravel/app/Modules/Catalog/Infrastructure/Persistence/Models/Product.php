<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Product Model
 *
 * Represents catalog products.
 * Soft deletion is supported and strictly restricted by database constraints.
 */
class Product extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Product $product): void {
            if (empty($product->public_id)) {
                $product->public_id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    protected $table = 'products';

    protected $fillable = [
        'public_id',
        'category_id',
        'slug',
        'name',
        'description',
        'short_description',
        'brand',
        'tags',
        'media',
        'specifications',
        'is_active',
        'is_featured',
        'status',
        'seo_title',
        'seo_description',
    ];

    protected $casts = [
        'tags'           => \App\Shared\Infrastructure\Database\Casts\PostgresTextArray::class,
        'media'          => 'array',
        'specifications' => \App\Shared\Infrastructure\Database\Casts\JsonObjectCast::class,
        'is_active'      => 'boolean',
        'is_featured'    => 'boolean',
        'created_at'     => 'datetime',
        'updated_at'     => 'datetime',
        'deleted_at'     => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    public function attributeDefinitions(): BelongsToMany
    {
        return $this->belongsToMany(
            AttributeDefinition::class,
            'product_attribute_definitions',
            'product_id',
            'attribute_id'
        )->withPivot(['is_required', 'sort_order'])->withTimestamps();
    }

    /**
     * Scope to filter only published, active, non-deleted products for public APIs.
     */
    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('status', 'published')
            ->whereNull('deleted_at');
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    /**
     * PostgreSQL full-text search against search_vector.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        if (trim($term) === '') {
            return $query;
        }

        return $query->whereRaw(
            "search_vector @@ websearch_to_tsquery('simple', ?)",
            [$term]
        );
    }
}
