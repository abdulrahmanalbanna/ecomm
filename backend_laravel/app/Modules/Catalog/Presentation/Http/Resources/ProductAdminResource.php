<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductAdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'public_id'         => $this->public_id,
            'category_id'       => $this->category_id,
            'slug'              => $this->slug,
            'name'              => $this->name,
            'description'       => $this->description,
            'short_description' => $this->short_description,
            'brand'             => $this->brand,
            'tags'              => $this->tags,
            'media'             => $this->media,
            'specifications'    => $this->specifications,
            'is_active'         => $this->is_active,
            'is_featured'       => $this->is_featured,
            'status'            => $this->status,
            'seo_title'         => $this->seo_title,
            'seo_description'   => $this->seo_description,
            'category'          => new CategoryResource($this->whenLoaded('category')),
            'attributes'        => AttributeDefinitionResource::collection($this->whenLoaded('attributeDefinitions')),
            'variants'          => ProductVariantAdminResource::collection($this->whenLoaded('variants')),
            'created_at'        => $this->created_at?->toIso8601String(),
            'updated_at'        => $this->updated_at?->toIso8601String(),
            'deleted_at'        => $this->deleted_at?->toIso8601String(),
        ];
    }
}
