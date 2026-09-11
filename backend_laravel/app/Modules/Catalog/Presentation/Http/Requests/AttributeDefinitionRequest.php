<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttributeDefinitionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return [
            'name'          => [
                'required',
                'string',
                'max:100',
                'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('attribute_definitions', 'name')->ignore($id),
            ],
            'display_name'  => ['required', 'string', 'max:200'],
            'type'          => ['required', 'string', 'in:text,number,boolean,select,multiselect'],
            'unit'          => ['nullable', 'string', 'max:30'],
            'is_filterable' => ['nullable', 'boolean'],
            'is_active'     => ['nullable', 'boolean'],
            'sort_order'    => ['nullable', 'integer', 'min:0'],
        ];
    }
}
