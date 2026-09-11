<?php

declare(strict_types=1);

namespace App\Modules\Cart\Presentation\Http\Resources;

use App\Modules\Catalog\Presentation\Http\Resources\ProductVariantPublicResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'variant_id'    => $this->variant_id,
            'quantity'      => $this->quantity,
            'unit_price'    => $this->unit_price,
            'line_subtotal' => $this->line_subtotal,
            'added_at'      => $this->added_at?->toIso8601String(),
            'variant'       => $this->whenLoaded('variant', fn () => new ProductVariantPublicResource($this->variant)),
        ];
    }
}
