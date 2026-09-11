<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions\Concerns;

use App\Modules\Payments\Domain\Exceptions\PaymentNotFoundException;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;

/**
 * FindsPayments — shared lookup helpers for Actions.
 *
 * findForUser() intentionally returns 404 (not 403) for payments belonging
 * to other customers: no existence leakage.
 */
trait FindsPayments
{
    protected function findForUser(string $publicId, int $userId): Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::forUser($userId)->where('public_id', $publicId)->first();

        if ($payment === null) {
            throw PaymentNotFoundException::forPublicId($publicId);
        }

        return $payment;
    }

    protected function findForAdmin(string $publicId): Payment
    {
        /** @var Payment|null $payment */
        $payment = Payment::where('public_id', $publicId)->first();

        if ($payment === null) {
            throw PaymentNotFoundException::forPublicId($publicId);
        }

        return $payment;
    }
}
