<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $productId = $this->route('id');

        return [
            'category_id'       => ['required', 'integer', 'exists:categories,id'],
            'slug'              => [
                'required',
                'string',
                'max:300',
                'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/',
                Rule::unique('products', 'slug')->ignore($productId),
            ],
            'name'              => ['required', 'string', 'max:300'],
            'description'       => ['nullable', 'string'],
            'short_description' => ['nullable', 'string'],
            'brand'             => ['nullable', 'string', 'max:150'],
            'tags'              => ['nullable', 'array'],
            'tags.*'            => ['string'],
            'media'             => ['nullable', 'array'],
            'specifications'    => ['nullable', 'array'],
            'is_active'         => ['nullable', 'boolean'],
            'is_featured'       => ['nullable', 'boolean'],
            'status'            => ['nullable', 'string', 'in:draft,published,archived'],
            'seo_title'         => ['nullable', 'string', 'max:300'],
            'seo_description'   => ['nullable', 'string'],
        ];
    }
}
