<?php

declare(strict_types=1);

namespace App\Modules\Payments\Domain\Exceptions;

/**
 * Installment plan / installment rule violations. Several are ALSO enforced
 * by PostgreSQL triggers (fn_validate_installment_plan,
 * fn_validate_installment_plan_count, fn_validate_installment_number);
 * the DB remains the final boundary. → HTTP 422.
 */
final class InstallmentException extends PaymentDomainException
{
    public static function requiresInstallmentMethod(): self
    {
        return new self('An installment plan requires a payment with method "installment".');
    }

    public static function invalidCount(int $count): self
    {
        return new self("Installment count {$count} is invalid. Allowed counts: 3, 4, 6.");
    }

    public static function countMismatch(int $requested, int $allowed): self
    {
        return new self("Installment count {$requested} does not match the gateway plan ({$allowed}).");
    }

    public static function numberExceedsPlan(int $number, int $planCount): self
    {
        return new self("Installment number {$number} exceeds the plan count {$planCount}.");
    }

    public static function alreadyExists(): self
    {
        return new self('An installment plan already exists for this payment.');
    }

    public static function notFound(): self
    {
        return new self('Installment not found for this payment.');
    }

    public static function alreadySettled(int $number): self
    {
        return new self("Installment {$number} is already settled.");
    }

    public static function outOfSequence(int $number): self
    {
        return new self("Installment {$number} cannot be settled before earlier installments are settled.");
    }
}
