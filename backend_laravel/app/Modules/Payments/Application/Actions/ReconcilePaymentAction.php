<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Actions\Concerns\FindsPayments;
use App\Modules\Payments\Application\DTOs\ReconcilePaymentData;
use App\Modules\Payments\Application\DTOs\SettleTransactionData;
use App\Modules\Payments\Application\Services\OrderPaymentSyncService;
use App\Modules\Payments\Application\Services\PaymentAmountService;
use App\Modules\Payments\Application\Services\PaymentGatewayResolver;
use App\Modules\Payments\Application\Services\PaymentReconciliationService;
use App\Modules\Payments\Application\Services\PaymentStateTransitionService;
use App\Modules\Payments\Domain\Contracts\GatewayResult;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use App\Modules\Payments\Infrastructure\Persistence\Models\PaymentTransaction;

/**
 * ReconcilePaymentAction — admin-triggered audit that compares the local
 * ledger against the gateway's authoritative view of a payment.
 *
 * Flow (gateway HTTP happens OUTSIDE any DB transaction):
 *  1. Query the gateway for the payment's current status.
 *  2. Build the read-only discrepancy report (PaymentReconciliationService).
 *  3. Apply CONSERVATIVE corrections when the evidence supports them:
 *     - gateway says SUCCESS but the local ledger is missing the settled
 *       charge  → settle it (idempotent via gateway_transaction_id UNIQUE).
 *     - derived expected status differs from local status → walk the state
 *       machine hop-by-hop along the SHORTEST legal path. Illegal or
 *       unreachable targets are never forced; they are reported instead
 *       ('uncorrectable_status_mismatch') for manual review.
 *  4. Sync the order when the corrected status implies it (paid → confirmed,
 *     failed → failed) via OrderPaymentSyncService.
 *
 * Every status write still goes through PaymentStateTransitionService, so the
 * DB trigger remains the final boundary even here.
 */
class ReconcilePaymentAction
{
    use FindsPayments;

    public function __construct(
        private readonly PaymentGatewayResolver $gateways,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly PaymentStateTransitionService $transitions,
        private readonly SettlePaymentTransactionAction $settle,
        private readonly OrderPaymentSyncService $orderSync,
        private readonly PaymentAmountService $amounts,
    ) {
    }

    /**
     * @return array{report: array<string, mixed>, corrections: list<string>}
     */
    public function execute(ReconcilePaymentData $dto): array
    {
        $payment = $this->findForAdmin($dto->paymentPublicId);
        $payment->loadMissing(['gateway', 'order']);

        $code = (string) $payment->gateway->code;
        $processor = $this->gateways->resolveProcessor($code);

        // Gateway HTTP first — never hold a DB transaction open across it.
        $snapshot = $processor->queryPaymentStatus($payment);

        $corrections = [];

        // 1) Settle a missing charge when the gateway confirms capture.
        $missingCharge = $this->settleMissingCharge($payment, $snapshot, $dto);

        if ($missingCharge !== null) {
            $corrections[] = $missingCharge;
            $payment->refresh();
        }

        // 2) Fresh report after the ledger correction.
        $report = $this->reconciliation->report($payment->fresh(), $snapshot);

        // 3) Status correction along the shortest legal path.
        $expected = $report['expected_status'];

        if (\is_string($expected) && $expected !== $report['local_status']) {
            $path = $this->shortestPath((string) $report['local_status'], $expected);

            if ($path === null) {
                $report['discrepancies'][] = 'uncorrectable_status_mismatch';
            } else {
                $payment = $this->applyPath($payment, $path, $snapshot, $dto);
                $corrections[] = 'status_corrected:' . $report['local_status'] . '->' . $expected;
                $report = $this->reconciliation->report($payment->fresh(), $snapshot);
            }
        }

        // 4) Order sync for corrected terminal outcomes.
        $payment->refresh();
        $order = $payment->order;

        if ($order !== null) {
            if ((string) $payment->status === PaymentStatus::PAID) {
                $this->orderSync->markPaid($order, $dto->performedBy);
            } elseif ((string) $payment->status === PaymentStatus::FAILED) {
                $this->orderSync->markFailed($order, $dto->performedBy);
            }
        }

        return [
            'report' => $report,
            'corrections' => $corrections,
        ];
    }

    /**
     * Settle the outstanding charge when the gateway reports success but the
     * local settled charges fall short of the intent amount.
     *
     * Requires a gateway transaction reference — settling with a synthesized
     * id would defeat webhook deduplication, so a missing reference is
     * reported instead of guessed.
     */
    private function settleMissingCharge(Payment $payment, GatewayResult $snapshot, ReconcilePaymentData $dto): ?string
    {
        if (! $snapshot->isSuccess()) {
            return null;
        }

        $settled = (string) $payment->transactions()
            ->where('transaction_type', PaymentTransaction::TYPE_CHARGE)
            ->whereNotNull('settled_at')
            ->selectRaw('ROUND(COALESCE(SUM(amount), 0), 2)::text as agg')
            ->value('agg');

        $missing = $this->amounts->subtract((string) $payment->amount, $settled);

        if (! $this->amounts->isPositive($missing)) {
            return null; // ledger already covers the intent (or exceeds it — report flags it)
        }

        if ($snapshot->transactionId === null || $snapshot->transactionId === '') {
            return 'missing_charge_unsettled_no_gateway_reference';
        }

        $transaction = $this->settle->execute(new SettleTransactionData(
            paymentId: (int) $payment->id,
            attemptId: null,
            transactionType: PaymentTransaction::TYPE_CHARGE,
            gatewayTransactionId: $snapshot->transactionId,
            amount: $missing,
            currency: (string) $payment->currency,
            settledAt: null,
            metadata: [
                'source' => 'reconciliation',
                'performed_by' => $dto->performedBy,
                'gateway_status' => $snapshot->raw['status'] ?? null,
            ],
        ));

        return $transaction === null
            ? 'missing_charge_already_settled'
            : 'settled_missing_charge';
    }

    /**
     * Walk the payment along a pre-computed list of legal hops. Each hop runs
     * through PaymentStateTransitionService (row lock + trigger boundary).
     *
     * @param list<string> $path
     */
    private function applyPath(Payment $payment, array $path, GatewayResult $snapshot, ReconcilePaymentData $dto): Payment
    {
        foreach ($path as $hop) {
            $payment = $this->transitions->transition(
                $payment,
                $hop,
                static function (Payment $locked) use ($snapshot): void {
                    // Settled states require a gateway reference
                    // (chk_payments_gateway_id_on_paid). Backfill it from the
                    // live snapshot while the row is locked, before the write.
                    if (
                        ($locked->gateway_payment_id === null || $locked->gateway_payment_id === '')
                        && $snapshot->transactionId !== null
                    ) {
                        $locked->gateway_payment_id = $snapshot->transactionId;
                        $locked->save();
                    }
                },
            );
        }

        return $payment;
    }

    /**
     * Breadth-first search over PaymentStatus::transitions() returning the
     * shortest hop sequence from $from to $to (excluding $from), or null when
     * no legal path exists.
     *
     * @return list<string>|null
     */
    private function shortestPath(string $from, string $to): ?array
    {
        if ($from === $to) {
            return [];
        }

        $queue = [[$from]];
        $seen = [$from => true];

        while ($queue !== []) {
            /** @var list<string> $path */
            $path = array_shift($queue);
            $current = (string) end($path);

            foreach (PaymentStatus::transitions()[$current] ?? [] as $next) {
                if (isset($seen[$next])) {
                    continue;
                }

                $candidate = [...$path, $next];

                if ($next === $to) {
                    /** @var list<string> $hops */
                    $hops = array_slice($candidate, 1);

                    return $hops;
                }

                $seen[$next] = true;
                $queue[] = $candidate;
            }
        }

        return null;
    }
}
