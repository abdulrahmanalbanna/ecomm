<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Manual installment settlement validation (admin). The gateway transaction
 * reference is required so settlement stays idempotent (UNIQUE constraint).
 */
class SettleInstallmentRequest extends FormRequest
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
            'gateway_transaction_id' => ['required', 'string', 'max:255'],
            'settled_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
