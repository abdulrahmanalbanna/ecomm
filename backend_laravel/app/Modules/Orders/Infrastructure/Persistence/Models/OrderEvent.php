<?php

declare(strict_types=1);

namespace App\Modules\Orders\Infrastructure\Persistence\Models;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use App\Shared\Infrastructure\Database\Casts\JsonObjectCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * OrderEvent Model
 *
 * Maps to authoritative PostgreSQL baseline table: order_events
 * (008_orders.sql) — RANGE-partitioned by created_at (monthly partitions
 * 2025–2028 + default). All reads/writes go through the partition PARENT
 * table; PostgreSQL routes rows to the correct partition automatically.
 *
 * Append-only audit trail: rows are never updated or deleted (no PUT/PATCH/
 * DELETE endpoints exist for events). The table has no updated_at column.
 *
 * Primary key is composite (id, created_at); `id` is populated by the
 * order_events_id_seq sequence via column DEFAULT, so Eloquent's
 * auto-increment handling is disabled.
 */
class OrderEvent extends Model
{
    protected $table = 'order_events';

    public const UPDATED_AT = null;

    /**
     * id comes from order_events_id_seq (column DEFAULT nextval(...)).
     */
    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'triggered_by',
        'note',
        'metadata',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata'   => JsonObjectCast::class,
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }
}
