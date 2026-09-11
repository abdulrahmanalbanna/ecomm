<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Installment — one scheduled payment period within an installment plan.
 * Maps to installments (database/sql/012_installments_refunds.sql).
 *
 * Baseline rules:
 *  - UNIQUE(plan_id, installment_number) — sequential numbering
 *  - trg_installments_validate_number: number may not exceed the plan count
 *  - UNIQUE(gateway_transaction_id) — idempotent settlement per installment
 *  - status CHECK ('pending','paid','failed','overdue','waived')
 */
class Installment extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PAID    = 'paid';
    public const STATUS_FAILED  = 'failed';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_WAIVED  = 'waived';

    protected $table = 'installments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'plan_id',
        'installment_number',
        'amount',
        'due_date',
        'paid_at',
        'gateway_transaction_id',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'paid_at'  => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    public function isSettled(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
