<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Refund creation validation (admin).
 *
 * The amount is a decimal STRING validated with a regex (never a float cast);
 * it is checked against the refundable balance by RefundBalanceService and,
 * finally, by the trg_refunds_validate_total DB trigger.
 */
class CreateRefundRequest extends FormRequest
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
            'amount' => [
                'required',
                'string',
                'regex:/^\d{1,10}(\.\d{1,2})?$/',
            ],
            'reason' => ['nullable', 'string', 'max:1000'],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.regex' => 'The refund amount must be a positive decimal with at most 2 fractional digits.',
        ];
    }
}
