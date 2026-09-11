<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Persistence\Models;

use App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant;
use App\Shared\Infrastructure\Database\Casts\JsonObjectCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderItem Model
 *
 * Maps to authoritative PostgreSQL baseline table: order_items (008_orders.sql).
 * Immutable line items: product_snapshot preserves catalog state at purchase time.
 * The table has no updated_at column, and the baseline treats rows as append-only.
 *
 * DB CHECK: total_price = quantity * unit_price (exact NUMERIC arithmetic).
 * Monetary columns are NOT float-cast; they are exact decimal strings.
 */
class OrderItem extends Model
{
    protected $table = 'order_items';

    /**
     * order_items has no updated_at column in the baseline.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'variant_id',
        'product_snapshot',
        'sku',
        'name',
        'quantity',
        'unit_price',
        'total_price',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_snapshot' => JsonObjectCast::class,
            'quantity'         => 'integer',
            'created_at'       => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
