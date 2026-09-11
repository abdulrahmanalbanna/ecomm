<?php

declare(strict_types=1);

namespace App\Modules\Customer\Presentation\Http\Requests;

use App\Modules\Customer\Application\DTOs\UpdateAddressData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label'          => ['sometimes', 'nullable', 'string', 'max:50'],
            'recipient_name' => ['sometimes', 'string', 'max:200'],
            'phone'          => ['sometimes', 'nullable', 'string', 'max:30'],
            'line1'          => ['sometimes', 'string'],
            'line2'          => ['sometimes', 'nullable', 'string'],
            'city'           => ['sometimes', 'string', 'max:100'],
            'state'          => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code'    => ['sometimes', 'nullable', 'string', 'max:20'],
            'country_code'   => ['sometimes', 'string', 'size:2'],
            'is_default'     => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('country_code')) {
            $this->merge([
                'country_code' => strtoupper((string) $this->input('country_code')),
            ]);
        }
    }

    public function toDTO(): UpdateAddressData
    {
        return new UpdateAddressData(
            label: $this->validated('label'),
            recipientName: $this->validated('recipient_name'),
            phone: $this->validated('phone'),
            line1: $this->validated('line1'),
            line2: $this->validated('line2'),
            city: $this->validated('city'),
            state: $this->validated('state'),
            postalCode: $this->validated('postal_code'),
            countryCode: $this->validated('country_code'),
            isDefault: $this->validated('is_default'),
        );
    }
}
