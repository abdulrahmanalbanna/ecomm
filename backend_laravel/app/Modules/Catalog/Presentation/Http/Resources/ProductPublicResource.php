<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Resources;

use App\Modules\Catalog\Support\CatalogMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductPublicResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'public_id'         => $this->public_id,
            'slug'              => $this->slug,
            'name'              => $this->name,
            'description'       => $this->description,
            'short_description' => $this->short_description,
            'brand'             => $this->brand,
            'tags'              => $this->tags,
            'media'             => CatalogMedia::normalizeMedia($this->media),
            'specifications'    => $this->specifications,
            'is_featured'       => $this->is_featured,
            'seo_title'         => $this->seo_title,
            'seo_description'   => $this->seo_description,
            'category'          => new CategoryResource($this->whenLoaded('category')),
            'attributes'        => AttributeDefinitionResource::collection($this->whenLoaded('attributeDefinitions')),
            'variants'          => ProductVariantPublicResource::collection($this->whenLoaded('variants')),
        ];
    }
}
