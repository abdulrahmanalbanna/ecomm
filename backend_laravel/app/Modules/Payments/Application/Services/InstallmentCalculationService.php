<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Services;

use App\Modules\Payments\Domain\Exceptions\InstallmentException;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use DateInterval;
use DateTimeImmutable;

/**
 * InstallmentCalculationService — pure schedule math for installment plans.
 *
 * Tabby: 4 equal installments. Tamara: 3 or 6. The DB CHECK on
 * installment_plans.number_of_installments IN (3,4,6) is authoritative; this
 * service mirrors it for friendly 422s and computes the equal split with the
 * rounding remainder assigned to the FIRST installment (the only way to keep
 * every installment > 0 under the NUMERIC CHECK amount > 0).
 *
 * All math is bcmath at scale 2 — no floats.
 */
final class InstallmentCalculationService
{
    /**
     * @var list<int>
     */
    public const VALID_COUNTS = [3, 4, 6];

    /**
     * @var list<string>
     */
    public const FREQUENCIES = ['monthly', 'biweekly', 'weekly'];

    /**
     * @throws InstallmentException when the count is not 3/4/6
     */
    public function assertValidCount(int $count): void
    {
        if (! in_array($count, self::VALID_COUNTS, true)) {
            throw InstallmentException::invalidCount($count);
        }
    }

    /**
     * Split a payment amount into $count installments.
     *
     * @return list<string> exact decimal strings; index 0 carries the remainder
     *
     * @throws InstallmentException
     */
    public function split(Payment $payment, int $count): array
    {
        $this->assertValidCount($count);

        $total = bcadd((string) $payment->amount, '0', PaymentAmountService::SCALE);

        // Work in integer cents for an exact floor division.
        $cents = (int) bcmul($total, '100', 0);
        $per = intdiv($cents, $count);
        $remainder = $cents - ($per * $count);

        if ($per <= 0) {
            throw new InstallmentException('Payment amount is too small to split into ' . $count . ' installments.');
        }

        $schedule = [];

        for ($i = 0; $i < $count; $i++) {
            $installmentCents = $per + ($i === 0 ? $remainder : 0);
            $schedule[] = bcdiv((string) $installmentCents, '100', PaymentAmountService::SCALE);
        }

        return $schedule;
    }

    /**
     * The base (non-remainder) installment amount stored on the plan row.
     *
     * @throws InstallmentException
     */
    public function planInstallmentAmount(Payment $payment, int $count): string
    {
        $schedule = $this->split($payment, $count);

        // Index 1 is the base share (index 0 may carry the remainder).
        return $count > 1 ? $schedule[1] : $schedule[0];
    }

    /**
     * Due dates for each installment (index 0 = first payment date).
     *
     * @return list<DateTimeImmutable>
     */
    public function dueDates(DateTimeImmutable $first, int $count, string $frequency): array
    {
        $step = match ($frequency) {
            'weekly'   => new DateInterval('P7D'),
            'biweekly' => new DateInterval('P14D'),
            default    => new DateInterval('P1M'),
        };

        $dates = [];
        $cursor = $first;

        for ($i = 0; $i < $count; $i++) {
            $dates[] = $cursor;
            $cursor = $cursor->add($step);
        }

        return $dates;
    }
}
