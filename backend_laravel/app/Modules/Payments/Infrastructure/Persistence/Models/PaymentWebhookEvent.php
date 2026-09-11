<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PaymentWebhookEvent — raw inbound gateway events, persisted BEFORE
 * processing for idempotency, audit and replay. Maps to
 * payment_webhook_events (database/sql/011_payments.sql).
 *
 * Baseline: UNIQUE(gateway_event_id) deduplicates re-deliveries
 * (INSERT ... ON CONFLICT DO NOTHING). processed=false + error_message
 * allow failed events to be retried/replayed.
 *
 * NOTE: the baseline table has created_at only (no updated_at).
 */
class PaymentWebhookEvent extends Model
{
    protected $table = 'payment_webhook_events';

    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'gateway_id',
        'event_type',
        'gateway_event_id',
        'payload',
        'processed',
        'processed_at',
        'error_message',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload'      => 'array',
            'processed'    => 'boolean',
            'processed_at' => 'datetime',
            'created_at'   => 'datetime',
        ];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'gateway_id');
    }
}
