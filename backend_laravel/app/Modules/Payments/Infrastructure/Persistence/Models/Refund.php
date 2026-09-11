<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Refund — partial or full refund against a payment. Maps to refunds
 * (database/sql/012_installments_refunds.sql).
 *
 * Baseline rules:
 *  - UNIQUE(gateway_refund_id) — idempotent refund recording
 *  - trg_refunds_validate_total: aggregate of active refunds ('pending',
 *    'processing', 'processed') may never exceed payments.amount. The trigger
 *    SELECTs the payment FOR UPDATE, serializing concurrent refund creation.
 *  - status CHECK ('pending','processing','processed','failed')
 */
class Refund extends Model
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PROCESSED  = 'processed';
    public const STATUS_FAILED     = 'failed';

    /**
     * Statuses counted toward the refundable balance by the DB trigger.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_PROCESSED];

    protected $table = 'refunds';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'transaction_id',
        'amount',
        'reason',
        'gateway_refund_id',
        'status',
        'initiated_by',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }
}
