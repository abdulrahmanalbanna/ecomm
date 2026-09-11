<?php

declare(strict_types=1);

namespace App\Modules\Payments\Presentation\Http\Requests;

use App\Modules\Payments\Domain\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create payment intent validation.
 *
 * The AMOUNT is never accepted from the client — PaymentAmountService derives
 * it from the order totals. Only the order reference, gateway, method,
 * optional installment count, and an idempotency key are supplied.
 */
class CreatePaymentIntentRequest extends FormRequest
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
            'order_public_id' => ['required', 'uuid', 'exists:orders,public_id'],
            'gateway' => ['required', 'string', Rule::in(['tabby', 'tamara'])],
            'payment_method' => ['required', 'string', Rule::in(PaymentMethod::all())],
            'number_of_installments' => [
                Rule::requiredIf(fn (): bool => $this->input('payment_method') === PaymentMethod::INSTALLMENT),
                'nullable',
                'integer',
                Rule::in([3, 4, 6]),
            ],
            'idempotency_key' => ['nullable', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'number_of_installments.in' => 'Installment count must be one of 3, 4, or 6.',
        ];
    }
}
