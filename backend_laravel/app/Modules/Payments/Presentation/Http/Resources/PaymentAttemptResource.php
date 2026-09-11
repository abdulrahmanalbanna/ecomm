<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin view of a gateway execution attempt (may have failed — distinct
 * from a settled transaction).
 */
class PaymentAttemptResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'attempt_number' => (int) $this->attempt_number,
            'status' => $this->status,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'gateway_transaction_id' => $this->gateway_transaction_id,
            'failure_code' => $this->failure_code,
            'failure_message' => $this->failure_message,
            'attempted_at' => $this->attempted_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
