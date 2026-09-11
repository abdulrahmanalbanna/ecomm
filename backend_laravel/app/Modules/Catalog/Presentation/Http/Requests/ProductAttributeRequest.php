<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductAttributeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'attribute_id' => ['required', 'integer', 'exists:attribute_definitions,id'],
            'is_required'  => ['nullable', 'boolean'],
            'sort_order'   => ['nullable', 'integer', 'min:0'],
        ];
    }
}
