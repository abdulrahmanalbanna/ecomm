<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Actions\Concerns\FindsPayments;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;

/**
 * GetPaymentAction — single payment retrieval.
 *
 * Customer lookups are ownership-scoped (another customer's payment resolves
 * to 404, never 403 — no existence leakage). Admin lookups are unscoped and
 * guarded by the payments.* permissions at the route layer.
 */
class GetPaymentAction
{
    use FindsPayments;

    public function forUser(string $publicId, int $userId): Payment
    {
        return $this->findForUser($publicId, $userId)
            ->load(['gateway', 'order', 'installmentPlan.installments', 'attempts']);
    }

    public function forAdmin(string $publicId): Payment
    {
        return $this->findForAdmin($publicId)
            ->load([
                'gateway',
                'order',
                'attempts',
                'transactions',
                'installmentPlan.installments',
                'refunds',
            ]);
    }
}
