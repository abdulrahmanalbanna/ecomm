<?php

declare(strict_types=1);

namespace App\Modules\Customer\Presentation\Http\Requests;

use App\Modules\Customer\Application\DTOs\UpdateCustomerProfileData;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCustomerProfileRequest extends FormRequest
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
            'first_name'  => ['sometimes', 'string', 'max:100'],
            'last_name'   => ['sometimes', 'string', 'max:100'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender'      => ['sometimes', 'nullable', 'string', 'max:20'],
            'avatar_url'  => ['sometimes', 'nullable', 'url', 'max:2048'],
            'preferences' => ['sometimes', 'array'],
            'preferences.*' => ['mixed'],
        ];
    }

    public function toDTO(): UpdateCustomerProfileData
    {
        return new UpdateCustomerProfileData(
            firstName: $this->validated('first_name'),
            lastName: $this->validated('last_name'),
            dateOfBirth: $this->validated('date_of_birth'),
            gender: $this->validated('gender'),
            avatarUrl: $this->validated('avatar_url'),
            preferences: $this->validated('preferences'),
        );
    }
}
