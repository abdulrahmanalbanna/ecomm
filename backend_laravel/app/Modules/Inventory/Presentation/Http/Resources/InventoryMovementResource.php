<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Http\Resources;

use App\Modules\Catalog\Presentation\Http\Resources\ProductVariantAdminResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'variant_id'      => $this->variant_id,
            'order_id'        => $this->order_id,
            'movement_type'   => $this->movement_type,
            'quantity_delta'  => $this->quantity_delta,
            'on_hand_after'   => $this->on_hand_after,
            'note'            => $this->note,
            'created_by'      => $this->created_by,
            'created_at'      => $this->created_at?->toIso8601String(),
            'variant'         => new ProductVariantAdminResource($this->whenLoaded('variant')),
            'created_by_user' => $this->whenLoaded('createdBy', fn () => [
                'id'    => $this->createdBy->id,
                'email' => $this->createdBy->email,
            ]),
        ];
    }
}
