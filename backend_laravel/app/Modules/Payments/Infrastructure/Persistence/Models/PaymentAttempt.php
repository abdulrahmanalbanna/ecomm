<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PaymentAttempt — one gateway EXECUTION attempt for a payment intent.
 * Maps to payment_attempts (database/sql/011_payments.sql).
 *
 * Distinct from PaymentTransaction: an attempt is an interaction with the
 * gateway (may fail); a transaction is a SETTLED financial movement.
 *
 * Baseline: UNIQUE(payment_id, attempt_number); status CHECK
 * ('pending','success','failed','cancelled','expired').
 *
 * NOTE: the baseline table has NO created_at/updated_at columns —
 * timestamps are disabled on this model.
 */
class PaymentAttempt extends Model
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_SUCCESS   = 'success';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED   = 'expired';

    protected $table = 'payment_attempts';

    const UPDATED_AT = null;
    const CREATED_AT = 'attempted_at';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'attempt_number',
        'gateway_transaction_id',
        'amount',
        'currency',
        'status',
        'failure_code',
        'failure_message',
        'gateway_response',
        'attempted_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'attempted_at'     => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'attempt_id');
    }
}
