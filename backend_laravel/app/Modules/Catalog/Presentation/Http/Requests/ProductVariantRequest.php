<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variantId = $this->route('variantId');

        return [
            'sku'              => [
                'required',
                'string',
                'max:100',
                'regex:/^[A-Z0-9]+(-[A-Z0-9]+)*$/i',
                Rule::unique('product_variants', 'sku')->ignore($variantId),
            ],
            'name'             => ['nullable', 'string', 'max:300'],
            'price'            => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'min:0'],
            'cost_price'       => ['nullable', 'numeric', 'min:0'],
            'weight_grams'     => ['nullable', 'integer', 'min:1'],
            'dimensions'       => ['nullable', 'array'],
            'attributes'       => ['nullable', 'array'],
            'media'            => ['nullable', 'array'],
            'is_active'        => ['nullable', 'boolean'],
        ];
    }
}
