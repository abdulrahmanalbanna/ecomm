<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Domain\Contracts\GatewayResult;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentWebhookEvent;
use App\Modules\Payments\Infrastructure\Persistence\Models\Refund;
use Illuminate\Database\Eloquent\Builder;

/**
 * PaymentReconciliationService — compares local ledger state against gateway
 * evidence (webhook events + settled transactions) and produces a structured
 * discrepancy report.
 *
 * It is READ-ONLY: it never mutates payment state. The ReconcilePaymentAction
 * consumes its report and decides which corrections (via
 * PaymentStateTransitionService / SettlePaymentTransactionAction) are legal.
 *
 * Webhook events are matched to a payment by the gateway session reference
 * inside the JSONB payload (payment_id / checkout_id / reference_code) — the
 * baseline payment_webhook_events table has no payment FK by design.
 */
final class PaymentReconciliationService
{
    public function __construct(
        private readonly PaymentAmountService $amounts,
    ) {
    }

    /**
     * Events referencing this payment's gateway session.
     */
    public function eventsFor(Payment $payment): array
    {
        $reference = $payment->gateway_payment_id;

        if ($reference === null || $reference === '') {
            return [];
        }

        /** @var list<PaymentWebhookEvent> $events */
        $events = PaymentWebhookEvent::query()
            ->where('gateway_id', $payment->gateway_id)
            ->where(static function (Builder $q) use ($reference): void {
                $q->where('payload->>payment_id', $reference)
                    ->orWhere('payload->>checkout_id', $reference)
                    ->orWhere('payload->>reference_code', $reference);
            })
            ->orderBy('created_at')
            ->get()
            ->all();

        return $events;
    }

    /**
     * Build the reconciliation report for a payment.
     *
     * @return array{
     *     payment_id: int,
     *     local_status: string,
     *     gateway_status: ?string,
     *     gateway_result_status: ?string,
     *     settled_charges: string,
     *     settled_refunds: string,
     *     active_refunds: string,
     *     expected_status: ?string,
     *     discrepancies: list<string>,
     *     unprocessed_events: int
     * }
     */
    public function report(Payment $payment, ?GatewayResult $gatewaySnapshot = null): array
    {
        $settledCharges = $this->sumColumn($payment, PaymentTransaction::TYPE_CHARGE);
        $settledRefunds = $this->sumColumn($payment, PaymentTransaction::TYPE_REFUND);
        $activeRefunds = $this->activeRefundSum($payment);

        $discrepancies = [];

        // 1) Ledger vs intent: settled charges should not exceed the intent.
        if (! $this->amounts->gte((string) $payment->amount, $settledCharges)) {
            $discrepancies[] = 'settled_charges_exceed_payment_amount';
        }

        // 2) Refund ledger vs intent: processed refunds must not exceed amount.
        if (! $this->amounts->gte((string) $payment->amount, $settledRefunds)) {
            $discrepancies[] = 'refunds_exceed_payment_amount';
        }

        // 3) Status consistency with the gateway snapshot (when provided).
        $expected = $this->expectedStatus($payment, $gatewaySnapshot, $settledCharges, $settledRefunds);

        if ($gatewaySnapshot !== null && $expected !== null && $expected !== (string) $payment->status) {
            $discrepancies[] = 'status_mismatch';
        }

        // 4) Settled payment without a gateway reference violates the intent
        //    contract chk_payments_gateway_id_on_paid.
        if (in_array((string) $payment->status, PaymentStatus::settledStatuses(), true)
            && ($payment->gateway_payment_id === null || $payment->gateway_payment_id === '')) {
            $discrepancies[] = 'settled_without_gateway_reference';
        }

        $unprocessed = PaymentWebhookEvent::where('processed', false)
            ->where('gateway_id', $payment->gateway_id)
            ->count();

        return [
            'payment_id' => (int) $payment->id,
            'local_status' => (string) $payment->status,
            'gateway_status' => $gatewaySnapshot?->raw['status'] ?? null,
            'gateway_result_status' => $gatewaySnapshot?->status ?? null,
            'settled_charges' => $settledCharges,
            'settled_refunds' => $settledRefunds,
            'active_refunds' => $activeRefunds,
            'expected_status' => $expected,
            'discrepancies' => $discrepancies,
            'unprocessed_events' => $unprocessed,
        ];
    }

    /**
     * Derive the status the ledger evidence supports, if any.
     */
    private function expectedStatus(
        Payment $payment,
        ?GatewayResult $snapshot,
        string $settledCharges,
        string $settledRefunds,
    ): ?string {
        if ($snapshot === null) {
            return null;
        }

        if ($snapshot->status === GatewayResult::STATUS_SUCCESS) {
            $amount = (string) $payment->amount;

            if (bccomp($settledRefunds, $amount, PaymentAmountService::SCALE) === 0) {
                return PaymentStatus::REFUNDED;
            }

            if (bccomp($settledRefunds, '0.00', PaymentAmountService::SCALE) > 0) {
                return PaymentStatus::PARTIALLY_REFUNDED;
            }

            if (bccomp($settledCharges, $amount, PaymentAmountService::SCALE) < 0) {
                return PaymentStatus::PARTIALLY_PAID;
            }

            return PaymentStatus::PAID;
        }

        if ($snapshot->status === GatewayResult::STATUS_FAILED) {
            return PaymentStatus::FAILED;
        }

        return null;
    }

    private function sumColumn(Payment $payment, string $type): string
    {
        $sum = (string) $payment->transactions()
            ->where('transaction_type', $type)
            ->whereNotNull('settled_at')
            ->selectRaw('ROUND(COALESCE(SUM(amount), 0), 2)::text as agg')
            ->value('agg');

        return $this->amounts->normalize($sum);
    }

    private function activeRefundSum(Payment $payment): string
    {
        $sum = (string) $payment->refunds()
            ->whereIn('status', Refund::ACTIVE_STATUSES)
            ->selectRaw('ROUND(COALESCE(SUM(amount), 0), 2)::text as agg')
            ->value('agg');

        return $this->amounts->normalize($sum);
    }
}
