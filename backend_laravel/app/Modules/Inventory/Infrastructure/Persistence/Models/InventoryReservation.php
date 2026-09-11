<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Infrastructure\Persistence\Models;

use App\Modules\Inventory\Domain\Enums\ReservationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * InventoryReservation Model
 *
 * Mapped to inventory_reservations table.
 */
class InventoryReservation extends Model
{
    protected $table = 'inventory_reservations';

    protected $fillable = [
        'order_id',
        'variant_id',
        'quantity',
        'quantity_backordered',
        'status',
        'created_by',
        'expires_at',
    ];

    protected $casts = [
        'order_id'             => 'integer',
        'variant_id'           => 'integer',
        'quantity'             => 'integer',
        'quantity_backordered' => 'integer',
        'status'               => ReservationStatus::class,
        'created_by'           => 'integer',
        'expires_at'           => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(Inventory::class, 'variant_id', 'variant_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', ReservationStatus::ACTIVE);
    }
}
