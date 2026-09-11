<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Refund representation (admin). initiated_by is the staff user id — never
 * exposed on customer surfaces.
 */
class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'payment_public_id' => $this->whenLoaded(
                'payment',
                fn (): string => (string) $this->payment->public_id,
            ),
            'amount' => (string) $this->amount,
            'reason' => $this->reason,
            'status' => $this->status,
            'gateway_refund_id' => $this->gateway_refund_id,
            'initiated_by' => $this->initiated_by !== null ? (int) $this->initiated_by : null,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
