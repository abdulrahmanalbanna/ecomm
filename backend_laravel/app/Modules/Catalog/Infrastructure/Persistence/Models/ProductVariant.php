<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * ProductVariant Model
 *
 * Represents purchasable SKUs with product-scoped attribute JSONB configuration.
 * Soft deletion is supported and strictly restricted by database constraints.
 */
class ProductVariant extends Model
{
    use SoftDeletes;

    protected $table = 'product_variants';

    protected $fillable = [
        'product_id',
        'sku',
        'name',
        'price',
        'compare_at_price',
        'cost_price',
        'weight_grams',
        'dimensions',
        'attributes',
        'media',
        'is_active',
    ];

    protected $casts = [
        'price'            => 'float',
        'compare_at_price' => 'float',
        'cost_price'       => 'float',
        'weight_grams'     => 'integer',
        'dimensions'       => 'array',
        'attributes'       => \App\Shared\Infrastructure\Database\Casts\JsonObjectCast::class,
        'media'            => 'array',
        'is_active'        => 'boolean',
        'created_at'       => 'datetime',
        'updated_at'       => 'datetime',
        'deleted_at'       => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function inventory(): HasOne
    {
        return $this->hasOne(\App\Modules\Inventory\Infrastructure\Persistence\Models\Inventory::class, 'variant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at');
    }

    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_active', true)->whereNull('deleted_at')->where('price', '>', 0);
    }
}
