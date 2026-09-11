<?php

declare(strict_types=1);

namespace App\Modules\Cart\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'coupon_code'    => $this->coupon_code,
            'expires_at'     => $this->expires_at?->toIso8601String(),
            'items'          => CartItemResource::collection($this->whenLoaded('items', $this->items, collect())),
            'items_count'    => $this->items_count,
            'total_quantity' => $this->total_quantity,
            'subtotal'       => $this->subtotal,
        ];
    }
}
