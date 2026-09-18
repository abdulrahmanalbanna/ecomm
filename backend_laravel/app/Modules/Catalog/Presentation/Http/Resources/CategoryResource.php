<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Resources;

use App\Modules\Catalog\Support\CatalogMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'parent_id'   => $this->parent_id,
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'image_url'   => CatalogMedia::url($this->image_url),
            'is_active'   => $this->is_active,
            'sort_order'  => $this->sort_order,
            'path'        => (string) $this->path,
            'depth'       => $this->depth,
            'children'    => CategoryResource::collection($this->whenLoaded('children')),
            'created_at'  => $this->created_at?->toIso8601String(),
            'updated_at'  => $this->updated_at?->toIso8601String(),
        ];
    }
}
