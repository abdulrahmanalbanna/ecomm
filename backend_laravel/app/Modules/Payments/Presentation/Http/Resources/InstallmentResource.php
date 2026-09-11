<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single scheduled installment. gateway_transaction_id is omitted for
 * customers (ledger reference); it is included only in the admin resource.
 */
class InstallmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'installment_number' => (int) $this->installment_number,
            'amount' => (string) $this->amount,
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'paid_at' => $this->paid_at?->toIso8601String(),
        ];
    }
}
