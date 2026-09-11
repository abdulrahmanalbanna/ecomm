<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin order representation — full data including internal id and buyer.
 */
class OrderAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => (int) $this->id,
            'public_id'        => $this->public_id,
            'user'             => $this->whenLoaded('user', fn () => [
                'id'        => (int) $this->user->id,
                'public_id' => $this->user->public_id,
                'email'     => $this->user->email,
            ]),
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
            'metadata'         => (object) $this->metadata,
            'ip_address'       => $this->ip_address,
            'items'            => OrderItemResource::collection($this->whenLoaded('items', $this->items, collect())),
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
