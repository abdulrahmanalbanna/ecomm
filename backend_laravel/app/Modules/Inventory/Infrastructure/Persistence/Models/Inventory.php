<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inventory Model
 *
 * Mapped 1:1 with product_variants.
 * quantity_available is a PostgreSQL GENERATED STORED column and read-only in application.
 */
class Inventory extends Model
{
    public const CREATED_AT = null;

    public const UPDATED_AT = 'updated_at';

    protected $table = 'inventory';

    protected $fillable = [
        'variant_id',
        'quantity_on_hand',
        'quantity_reserved',
        'quantity_backordered',
        'reorder_point',
        'reorder_quantity',
        'allow_backorder',
    ];

    protected $casts = [
        'variant_id'           => 'integer',
        'quantity_on_hand'     => 'integer',
        'quantity_reserved'    => 'integer',
        'quantity_backordered' => 'integer',
        'quantity_available'   => 'integer',
        'reorder_point'        => 'integer',
        'reorder_quantity'     => 'integer',
        'allow_backorder'      => 'boolean',
        'updated_at'           => 'datetime',
    ];

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class, 'variant_id', 'variant_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id', 'variant_id');
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereRaw('quantity_available <= reorder_point');
    }

    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity_available', '>', 0);
    }
}
