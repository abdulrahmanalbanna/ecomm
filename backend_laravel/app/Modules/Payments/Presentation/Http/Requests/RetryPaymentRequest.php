<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Retry payment validation. Only an optional idempotency key is accepted —
 * the gateway and amount come from the existing payment intent.
 */
class RetryPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }
}
