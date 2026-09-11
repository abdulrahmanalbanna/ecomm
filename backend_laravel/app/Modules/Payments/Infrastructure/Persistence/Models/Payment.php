<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use App\Modules\Orders\Infrastructure\Persistence\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * Payment — the canonical payment INTENT. Maps to payments
 * (database/sql/011_payments.sql).
 *
 * Invariants enforced by the baseline:
 *  - UNIQUE(order_id)      → exactly one payment intent per order
 *  - UNIQUE(idempotency_key) → duplicate-charge protection on client retry
 *  - chk_payments_gateway_id_on_paid → settled states require gateway_payment_id
 *  - trg_payments_status_transition → payment_status_enum state machine (P0005)
 *
 * Money columns are NUMERIC(12,2) and intentionally NOT cast to float —
 * they are read/written as exact decimal strings (bcmath at scale 2),
 * mirroring the Orders module.
 */
class Payment extends Model
{
    protected $table = 'payments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'order_id',
        'gateway_id',
        'payment_method',
        'amount',
        'currency',
        'status',
        'idempotency_key',
        'gateway_payment_id',
        'gateway_response',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'gateway_response' => 'array',
            'created_at'       => 'datetime',
            'updated_at'       => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(static function (Payment $payment): void {
            if (empty($payment->public_id)) {
                $payment->public_id = (string) Str::uuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class, 'payment_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'payment_id');
    }

    /**
     * Webhook events that reference THIS payment.
     *
     * The baseline payment_webhook_events table has no payment_id FK (it is a
     * raw gateway audit log keyed by gateway_id + payload). We therefore match
     * on the gateway session reference inside the JSONB payload rather than
     * naively returning every event for the gateway — which would leak other
     * payments' events into reconciliation views.
     *
     * IMPORTANT: because the payload-matching constraint is derived from THIS
     * model's gateway_payment_id, the relation is for single-model (lazy)
     * access only. Do NOT eager-load it across collections; the reconciliation
     * service queries events explicitly instead.
     */
    public function webhookEvents(): HasMany
    {
        $reference = $this->gateway_payment_id;

        return $this->hasMany(PaymentWebhookEvent::class, 'gateway_id', 'gateway_id')
            ->where(static function (Builder $q) use ($reference): void {
                if ($reference === null || $reference === '') {
                    $q->whereRaw('1 = 0');

                    return;
                }

                $q->where('payload->>payment_id', $reference)
                    ->orWhere('payload->>checkout_id', $reference)
                    ->orWhere('payload->>reference_code', $reference);
            });
    }

    public function installmentPlan(): HasOne
    {
        return $this->hasOne(InstallmentPlan::class, 'payment_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class, 'payment_id');
    }

    /**
     * Scope payments to orders owned by a user (application-layer ownership;
     * RLS is not part of this baseline — same pattern as Order::scopeForUser).
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('order', static fn (Builder $q): Builder => $q->where('user_id', $userId));
    }

    /**
     * The most recent attempt (attempt_number DESC).
     */
    public function latestAttempt(): ?PaymentAttempt
    {
        return $this->attempts()->orderByDesc('attempt_number')->first();
    }
}
