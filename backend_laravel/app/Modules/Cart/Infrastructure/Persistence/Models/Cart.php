<?php

declare(strict_types=1);

namespace App\Modules\Cart\Infrastructure\Persistence\Models;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cart Model
 *
 * Mapped to PostgreSQL baseline table: carts
 * One active cart per user_id (UNIQUE constraint).
 */
class Cart extends Model
{
    protected $table = 'carts';

    protected $fillable = [
        'user_id',
        'coupon_code',
        'expires_at',
    ];

    protected $casts = [
        'user_id'    => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'cart_id');
    }

    /**
     * Number of distinct line items in the cart.
     */
    public function getItemsCountAttribute(): int
    {
        return $this->relationLoaded('items')
            ? $this->items->count()
            : $this->items()->count();
    }

    /**
     * Total sum of item quantities.
     */
    public function getTotalQuantityAttribute(): int
    {
        return $this->relationLoaded('items')
            ? (int) $this->items->sum('quantity')
            : (int) $this->items()->sum('quantity');
    }

    /**
     * Subtotal calculation based on variant prices of items.
     */
    public function getSubtotalAttribute(): string
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->with('variant')->get();

        $subtotal = 0.0;
        foreach ($items as $item) {
            $price = $item->variant ? (float) $item->variant->price : 0.0;
            $subtotal += $price * $item->quantity;
        }

        return number_format($subtotal, 2, '.', '');
    }
}
