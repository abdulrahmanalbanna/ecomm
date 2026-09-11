<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentTransaction — a SETTLED financial movement (charge, refund,
 * chargeback, adjustment). Maps to payment_transactions
 * (database/sql/011_payments.sql).
 *
 * Distinct from PaymentAttempt: only successful/settled events create rows
 * here. Rows are financial audit records — never edited after settlement.
 *
 * Baseline: UNIQUE(gateway_transaction_id) is the external idempotency
 * boundary (INSERT ... ON CONFLICT DO NOTHING for idempotent recording).
 *
 * NOTE: the baseline table has created_at only (no updated_at).
 */
class PaymentTransaction extends Model
{
    public const TYPE_CHARGE      = 'charge';
    public const TYPE_REFUND      = 'refund';
    public const TYPE_CHARGEBACK  = 'chargeback';
    public const TYPE_ADJUSTMENT  = 'adjustment';

    /**
     * @var list<string>
     */
    public const TYPES = [
        self::TYPE_CHARGE,
        self::TYPE_REFUND,
        self::TYPE_CHARGEBACK,
        self::TYPE_ADJUSTMENT,
    ];

    protected $table = 'payment_transactions';

    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'attempt_id',
        'transaction_type',
        'gateway_transaction_id',
        'amount',
        'currency',
        'settled_at',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata'   => 'array',
            'settled_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function attempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class, 'attempt_id');
    }
}
