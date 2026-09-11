<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a settled financial movement (charge/refund/chargeback/
 * adjustment). Amounts are exact decimal strings.
 */
class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'attempt_id' => $this->attempt_id !== null ? (int) $this->attempt_id : null,
            'transaction_type' => $this->transaction_type,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'settled_at' => $this->settled_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
