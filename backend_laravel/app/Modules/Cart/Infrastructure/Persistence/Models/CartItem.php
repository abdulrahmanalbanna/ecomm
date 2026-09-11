<?php

declare(strict_types=1);

namespace App\Modules\Cart\Infrastructure\Persistence\Models;

use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CartItem Model
 *
 * Mapped to PostgreSQL baseline table: cart_items
 * Unique constraint on (cart_id, variant_id).
 */
class CartItem extends Model
{
    public const CREATED_AT = 'added_at';

    public const UPDATED_AT = null;

    protected $table = 'cart_items';

    protected $fillable = [
        'cart_id',
        'variant_id',
        'quantity',
        'added_at',
    ];

    protected $casts = [
        'cart_id'    => 'integer',
        'variant_id' => 'integer',
        'quantity'   => 'integer',
        'added_at'   => 'datetime',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /**
     * Customer-facing unit price from ProductVariant.
     */
    public function getUnitPriceAttribute(): string
    {
        $price = $this->variant ? (float) $this->variant->price : 0.0;

        return number_format($price, 2, '.', '');
    }

    /**
     * Line subtotal = unit_price * quantity.
     */
    public function getLineSubtotalAttribute(): string
    {
        $price = $this->variant ? (float) $this->variant->price : 0.0;
        $lineSubtotal = $price * $this->quantity;

        return number_format($lineSubtotal, 2, '.', '');
    }
}
