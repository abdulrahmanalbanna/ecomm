<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Persistence\Models;

use App\Modules\Inventory\Domain\Enums\MovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InventoryMovement Model
 *
 * Mapped to PostgreSQL RANGE-partitioned parent table inventory_movements.
 * Append-only stock movement ledger.
 */
class InventoryMovement extends Model
{
    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $table = 'inventory_movements';

    protected $fillable = [
        'variant_id',
        'order_id',
        'reservation_id',
        'movement_type',
        'quantity_delta',
        'quantity_on_hand_delta',
        'quantity_reserved_delta',
        'on_hand_after',
        'quantity_on_hand_after',
        'quantity_reserved_after',
        'note',
        'created_by',
        'created_at',
    ];

    protected $casts = [
        'variant_id'              => 'integer',
        'order_id'                => 'integer',
        'reservation_id'          => 'integer',
        'movement_type'           => MovementType::class,
        'quantity_delta'          => 'integer',
        'quantity_on_hand_delta'  => 'integer',
        'quantity_reserved_delta' => 'integer',
        'on_hand_after'           => 'integer',
        'quantity_on_hand_after'  => 'integer',
        'quantity_reserved_after' => 'integer',
        'created_by'              => 'integer',
        'created_at'              => 'datetime',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'variant_id', 'variant_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Catalog\Infrastructure\Persistence\Models\ProductVariant::class, 'variant_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(InventoryReservation::class, 'reservation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Identity\Infrastructure\Persistence\Models\User::class, 'created_by');
    }
}
