<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer-facing order representation.
 *
 * The internal BIGINT id is NEVER exposed (public_id is the customer-facing
 * reference per the baseline column comment). Monetary values are exact
 * decimal strings.
 */
class OrderCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id'        => $this->public_id,
            'status'           => $this->status,
            'currency'         => $this->currency,
            'subtotal'         => (string) $this->subtotal,
            'discount_amount'  => (string) $this->discount_amount,
            'shipping_amount'  => (string) $this->shipping_amount,
            'tax_amount'       => (string) $this->tax_amount,
            'total_amount'     => (string) $this->total_amount,
            'coupon_code'      => $this->coupon_code,
            'notes'            => $this->notes,
            'shipping_address' => (object) $this->shipping_address,
            'billing_address'  => $this->billing_address !== null && $this->billing_address !== []
                ? (object) $this->billing_address
                : null,
            'items'            => OrderItemResource::collection($this->whenLoaded('items', $this->items, collect())),
            'items_count'      => $this->whenLoaded('items', fn () => $this->items->count()),
            'placed_at'        => $this->placed_at?->toIso8601String(),
            'confirmed_at'     => $this->confirmed_at?->toIso8601String(),
            'shipped_at'       => $this->shipped_at?->toIso8601String(),
            'delivered_at'     => $this->delivered_at?->toIso8601String(),
            'cancelled_at'     => $this->cancelled_at?->toIso8601String(),
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
        ];
    }
}
