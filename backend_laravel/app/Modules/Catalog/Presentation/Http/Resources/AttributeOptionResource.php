<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttributeOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'attribute_id' => $this->attribute_id,
            'value'        => $this->value,
            'display_name' => $this->display_name,
            'is_active'    => $this->is_active,
            'sort_order'   => $this->sort_order,
        ];
    }
}
