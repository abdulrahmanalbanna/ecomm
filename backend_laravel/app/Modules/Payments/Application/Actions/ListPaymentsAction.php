<?php

declare(strict_types=1);

namespace App\Modules\Payments\Application\Actions;

use App\Modules\Payments\Application\Actions\Concerns\FindsPayments;
use App\Modules\Payments\Application\DTOs\PaymentListFiltersData;
use App\Modules\Payments\Domain\PaymentStatus;
use App\Modules\Payments\Infrastructure\Persistence\Models\Payment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * ListPaymentsAction — paginated payment listing for customers (own payments
 * only, enforced via Payment::forUser) and admins (all payments + filters).
 */
class ListPaymentsAction
{
    use FindsPayments;

    public function execute(PaymentListFiltersData $filters): LengthAwarePaginator
    {
        $query = Payment::query()->with(['gateway', 'order', 'installmentPlan']);

        if ($filters->userId !== null) {
            $query->forUser($filters->userId);
        }

        if ($filters->status !== null) {
            if (PaymentStatus::isKnown($filters->status)) {
                $query->where('status', $filters->status);
            }
        }

        if ($filters->gatewayCode !== null) {
            $query->whereHas('gateway', static fn (Builder $q): Builder => $q->where('code', $filters->gatewayCode));
        }

        if ($filters->paymentMethod !== null) {
            $query->where('payment_method', $filters->paymentMethod);
        }

        if ($filters->dateFrom !== null) {
            $query->whereDate('created_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->whereDate('created_at', '<=', $filters->dateTo);
        }

        return $query->latest('id')->paginate($filters->perPage, ['*'], 'page', $filters->page);
    }
}
