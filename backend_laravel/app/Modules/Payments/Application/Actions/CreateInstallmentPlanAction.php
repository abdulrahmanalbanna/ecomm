<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Actions\Concerns\FindsPayments;
use App\Modules\Payments\Application\DTOs\CreateInstallmentPlanData;
use App\Modules\Payments\Application\Services\InstallmentCalculationService;
use App\Modules\Payments\Application\Services\PaymentGatewayResolver;
use App\Modules\Payments\Domain\Exceptions\InstallmentException;
use App\Modules\Payments\Domain\PaymentMethod;
use App\Modules\Payments\Infrastructure\Persistence\Models\Installment;
use App\Modules\Payments\Infrastructure\Persistence\Models\InstallmentPlan;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CreateInstallmentPlanAction — materializes the gateway's installment plan
 * locally: one installment_plans row (1:1 with the payment) plus N
 * installments rows with exact per-period amounts and due dates.
 *
 * DB boundaries (mirrored in PHP for friendly 422s):
 *  - trg_installment_plans_validate_payment: payment_method must be
 *    'installment' (23514)
 *  - number_of_installments CHECK IN (3,4,6)
 *  - UNIQUE(payment_id): one plan per payment
 *  - trg_installments_validate_number: number ≤ plan count
 *
 * The schedule is computed server-side (InstallmentCalculationService) —
 * client amounts are never trusted.
 */
class CreateInstallmentPlanAction
{
    use FindsPayments;

    public function __construct(
        private readonly InstallmentCalculationService $calculator,
        private readonly PaymentGatewayResolver $gatewayResolver,
    ) {
    }

    public function execute(CreateInstallmentPlanData $dto): InstallmentPlan
    {
        $payment = $this->findForAdmin($dto->paymentPublicId);

        if ((string) $payment->payment_method !== PaymentMethod::INSTALLMENT) {
            throw InstallmentException::requiresInstallmentMethod();
        }

        $this->calculator->assertValidCount($dto->numberOfInstallments);

        // The gateway row must actually support this count (Tabby 4, Tamara 3/6).
        $payment->loadMissing('gateway');

        if (! $payment->gateway->supportsInstallmentCount($dto->numberOfInstallments)) {
            throw InstallmentException::countMismatch(
                $dto->numberOfInstallments,
                (int) ($payment->gateway->installmentOptions()[0] ?? 0),
            );
        }

        // Idempotent replay: same plan already exists with the same count.
        $existing = InstallmentPlan::where('payment_id', $payment->id)->first();

        if ($existing !== null) {
            if ((int) $existing->number_of_installments === $dto->numberOfInstallments) {
                return $existing->load('installments');
            }

            throw InstallmentException::alreadyExists();
        }

        $schedule = $this->calculator->split($payment, $dto->numberOfInstallments);
        $dueDates = $this->calculator->dueDates(
            new DateTimeImmutable('today'),
            $dto->numberOfInstallments,
            'monthly',
        );

        try {
            return DB::transaction(function () use ($payment, $dto, $schedule, $dueDates): InstallmentPlan {
                /** @var InstallmentPlan $plan */
                $plan = InstallmentPlan::create([
                    'payment_id' => (int) $payment->id,
                    'number_of_installments' => $dto->numberOfInstallments,
                    'installment_amount' => $this->calculator->planInstallmentAmount($payment, $dto->numberOfInstallments),
                    'first_payment_date' => $dueDates[0]->format('Y-m-d'),
                    'frequency' => 'monthly',
                    'status' => InstallmentPlan::STATUS_ACTIVE,
                ]);

                foreach ($schedule as $i => $amount) {
                    /** @var Installment $installment */
                    Installment::create([
                        'plan_id' => (int) $plan->id,
                        'installment_number' => $i + 1,
                        'amount' => $amount,
                        'due_date' => $dueDates[$i]->format('Y-m-d'),
                        'status' => Installment::STATUS_PENDING,
                    ]);
                }

                return $plan->load('installments');
            });
        } catch (QueryException $e) {
            // 23514 = the payment_method trigger; 23505 = duplicate plan.
            if ((string) $e->getCode() === '23514') {
                throw InstallmentException::requiresInstallmentMethod();
            }

            if ((string) $e->getCode() === '23505') {
                throw InstallmentException::alreadyExists();
            }

            throw $e;
        }
    }
}
