<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Installment plan (with schedule) — safe for both customer and admin views.
 * Internal ids are not exposed; amounts are exact decimal strings.
 */
class InstallmentPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if ($this->resource === null) {
            return [];
        }

        return [
            'number_of_installments' => (int) $this->number_of_installments,
            'installment_amount' => (string) $this->installment_amount,
            'frequency' => $this->frequency,
            'first_payment_date' => $this->first_payment_date?->toDateString(),
            'status' => $this->status,
            'installments' => InstallmentResource::collection(
                $this->whenLoaded('installments', $this->installments, collect()),
            ),
        ];
    }
}
