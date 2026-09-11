<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Persistence\Models;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Shared\Infrastructure\Database\Casts\JsonObjectCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Order Model
 *
 * Maps to authoritative PostgreSQL baseline table: orders (008_orders.sql).
 * NOTE: `orders` is a NORMAL table in the baseline (the file header comment
 * mentioning partitioning is stale — actual DDL is CREATE TABLE orders).
 *
 * Monetary columns are NUMERIC(12,2) and are intentionally NOT cast to float.
 * They are read as exact decimal strings from PostgreSQL and written as exact
 * decimal strings computed with bcmath in CheckoutAction. The DB enforces:
 *  - chk_orders_total_formula: total = subtotal - discount + shipping + tax
 *  - trg_orders_reconcile_subtotal (deferred): subtotal = SUM(order_items.total_price)
 *  - trg_orders_status_transition: legal transitions per fn_validate_order_status_transition
 */
class Order extends Model
{
    protected $table = 'orders';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'user_id',
        'status',
        'subtotal',
        'discount_amount',
        'shipping_amount',
        'tax_amount',
        'total_amount',
        'currency',
        'coupon_code',
        'notes',
        'shipping_address',
        'billing_address',
        'metadata',
        'ip_address',
        'placed_at',
        'confirmed_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'shipping_address' => JsonObjectCast::class,
            'billing_address'  => JsonObjectCast::class,
            'metadata'         => JsonObjectCast::class,
            'placed_at'        => 'datetime',
            'confirmed_at'     => 'datetime',
            'shipped_at'       => 'datetime',
            'delivered_at'     => 'datetime',
            'cancelled_at'     => 'datetime',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OrderEvent::class, 'order_id');
    }

    /**
     * Scope orders to a specific user (application-layer ownership scoping;
     * RLS is not part of this baseline).
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function isTerminal(): bool
    {
        return \App\Modules\Orders\Domain\OrderStatus::transitions()[$this->status] === [];
    }
}
