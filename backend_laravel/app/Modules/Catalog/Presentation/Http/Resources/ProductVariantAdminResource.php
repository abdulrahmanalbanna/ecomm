<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVariantAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'product_id'       => $this->product_id,
            'sku'              => $this->sku,
            'name'             => $this->name,
            'price'            => $this->price,
            'compare_at_price' => $this->compare_at_price,
            'cost_price'       => $this->cost_price,
            'weight_grams'     => $this->weight_grams,
            'dimensions'       => $this->dimensions,
            'attributes'       => $this->attributes,
            'media'            => $this->media,
            'is_active'        => $this->is_active,
            'created_at'       => $this->created_at?->toIso8601String(),
            'updated_at'       => $this->updated_at?->toIso8601String(),
            'deleted_at'       => $this->deleted_at?->toIso8601String(),
        ];
    }
}
