<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin payment representation — the full ledger view: attempts, settled
 * transactions, refunds, installment plan, and the sanitized gateway response.
 *
 * Internal BIGINT ids are exposed here (admin tooling needs them for
 * installment settlement endpoints); secrets are never present because
 * gateway_response is redacted at the gateway boundary (AbstractGateway::redact).
 */
class PaymentAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'public_id' => $this->public_id,
            'order' => $this->whenLoaded('order', fn (): array => [
                'id' => (int) $this->order->id,
                'public_id' => $this->order->public_id,
                'user_id' => (int) $this->order->user_id,
                'status' => $this->order->status,
                'total_amount' => (string) $this->order->total_amount,
            ]),
            'gateway' => $this->whenLoaded('gateway', fn (): array => [
                'id' => (int) $this->gateway->id,
                'code' => $this->gateway->code,
                'name' => $this->gateway->name,
            ]),
            'payment_method' => $this->payment_method,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'idempotency_key' => $this->idempotency_key,
            'gateway_payment_id' => $this->gateway_payment_id,
            'gateway_response' => (object) ($this->gateway_response ?? []),
            'attempts' => PaymentAttemptResource::collection(
                $this->whenLoaded('attempts', $this->attempts, collect()),
            ),
            'transactions' => PaymentTransactionResource::collection(
                $this->whenLoaded('transactions', $this->transactions, collect()),
            ),
            'refunds' => RefundResource::collection(
                $this->whenLoaded('refunds', $this->refunds, collect()),
            ),
            'installment_plan' => new InstallmentPlanResource(
                $this->whenLoaded('installmentPlan'),
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
