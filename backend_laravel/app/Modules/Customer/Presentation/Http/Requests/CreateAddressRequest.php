<?php

declare(strict_types=1);

namespace App\Modules\Customer\Presentation\Http\Requests;

use App\Modules\Customer\Application\DTOs\CreateAddressData;
use Illuminate\Foundation\Http\FormRequest;

class CreateAddressRequest extends FormRequest
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
            'label'          => ['nullable', 'string', 'max:50'],
            'recipient_name' => ['required', 'string', 'max:200'],
            'phone'          => ['nullable', 'string', 'max:30'],
            'line1'          => ['required', 'string'],
            'line2'          => ['nullable', 'string'],
            'city'           => ['required', 'string', 'max:100'],
            'state'          => ['nullable', 'string', 'max:100'],
            'postal_code'    => ['nullable', 'string', 'max:20'],
            'country_code'   => ['required', 'string', 'size:2'],
            'is_default'     => ['nullable', 'boolean'],
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

    public function toDTO(): CreateAddressData
    {
        return new CreateAddressData(
            label: $this->validated('label'),
            recipientName: $this->validated('recipient_name'),
            phone: $this->validated('phone'),
            line1: $this->validated('line1'),
            line2: $this->validated('line2'),
            city: $this->validated('city'),
            state: $this->validated('state'),
            postalCode: $this->validated('postal_code'),
            countryCode: $this->validated('country_code'),
            isDefault: (bool) $this->validated('is_default', false),
        );
    }
}
