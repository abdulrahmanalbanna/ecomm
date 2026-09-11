<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\DTOs;

/**
 * Immutable input for CreateInstallmentPlanAction.
 *
 * The schedule is derived server-side by InstallmentCalculationService from
 * the payment amount — client amounts are never trusted.
 */
final readonly class CreateInstallmentPlanData
{
    public function __construct(
        public string $paymentPublicId,
        public int $numberOfInstallments,
        public ?int $createdBy = null,
    ) {
    }
}
