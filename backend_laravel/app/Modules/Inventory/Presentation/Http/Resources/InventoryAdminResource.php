<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Http\Resources;

use App\Modules\Catalog\Presentation\Http\Resources\ProductVariantAdminResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                 => $this->id,
            'variant_id'         => $this->variant_id,
            'quantity_on_hand'   => $this->quantity_on_hand,
            'quantity_reserved'  => $this->quantity_reserved,
            'quantity_available' => $this->quantity_available,
            'reorder_point'      => $this->reorder_point,
            'reorder_quantity'   => $this->reorder_quantity,
            'allow_backorder'    => $this->allow_backorder,
            'updated_at'         => $this->updated_at?->toIso8601String(),
            'variant'            => new ProductVariantAdminResource($this->whenLoaded('variant')),
        ];
    }
}
