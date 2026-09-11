<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Requests;

use App\Modules\Identity\Application\DTOs\LoginInputDTO;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function toDTO(): LoginInputDTO
    {
        return new LoginInputDTO(
            email: (string) $this->input('email'),
            password: (string) $this->input('password'),
            ipAddress: $this->ip(),
            userAgent: $this->userAgent()
        );
    }
}
