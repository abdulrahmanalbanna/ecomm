<?php

declare(strict_types=1);

namespace App\Modules\Payments\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * InstallmentPlan — 1:1 with a payment intent (UNIQUE payment_id).
 * Maps to installment_plans (database/sql/012_installments_refunds.sql).
 *
 * Baseline rules (DB-enforced, mirrored in PHP):
 *  - number_of_installments CHECK IN (3, 4, 6)
 *  - trg_installment_plans_validate_payment: requires payments.payment_method
 *    = 'installment' (SQLSTATE 23514)
 *  - trg_installment_plans_validate_count: count may not drop below the
 *    highest existing installment_number
 */
class InstallmentPlan extends Model
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DEFAULTED = 'defaulted';
    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'installment_plans';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'payment_id',
        'gateway_plan_id',
        'number_of_installments',
        'installment_amount',
        'first_payment_date',
        'frequency',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_payment_date' => 'date',
            'created_at'         => 'datetime',
            'updated_at'         => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class, 'plan_id');
    }
}
