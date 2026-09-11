<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-safe payment representation.
 *
 * Deliberately EXCLUDED (admin-only or sensitive):
 *  - internal BIGINT ids (public_id is the customer reference)
 *  - idempotency_key (server-side replay token)
 *  - gateway_response (raw gateway JSON — audit only)
 *  - payment attempts / transactions / webhook events (ledger detail)
 *  - refund initiator identity
 *
 * Monetary values are exact decimal strings, never floats.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'order' => $this->whenLoaded('order', fn (): array => [
                'public_id' => $this->order->public_id,
                'status' => $this->order->status,
                'total_amount' => (string) $this->order->total_amount,
            ]),
            'gateway' => $this->whenLoaded('gateway', fn (): array => [
                'code' => $this->gateway->code,
                'name' => $this->gateway->name,
            ]),
            'payment_method' => $this->payment_method,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'redirect_url' => $this->redirectUrl(),
            'installment_plan' => new InstallmentPlanResource(
                $this->whenLoaded('installmentPlan'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * The customer-facing checkout URL, extracted from the sanitized gateway
     * response. Gateways use different key names, so we probe the known set.
     */
    private function redirectUrl(): ?string
    {
        $response = $this->gateway_response;

        if (! \is_array($response)) {
            return null;
        }

        $web = $response['urls']['web'] ?? null;

        if (\is_string($web) && $web !== '') {
            return $web;
        }

        foreach (['checkoutUrl', 'checkout_url', 'redirectUrl', 'paymentUrl'] as $key) {
            $value = $response[$key] ?? null;

            if (\is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
