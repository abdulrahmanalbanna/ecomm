<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeDefinitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'display_name'  => $this->display_name,
            'type'          => $this->type,
            'unit'          => $this->unit,
            'is_filterable' => $this->is_filterable,
            'is_active'     => $this->is_active,
            'sort_order'    => $this->sort_order,
            'pivot'         => $this->whenPivotLoaded('product_attribute_definitions', function () {
                return [
                    'is_required' => (bool) $this->pivot->is_required,
                    'sort_order'  => (int) $this->pivot->sort_order,
                ];
            }),
            'options'       => AttributeOptionResource::collection($this->whenLoaded('options')),
        ];
    }
}
