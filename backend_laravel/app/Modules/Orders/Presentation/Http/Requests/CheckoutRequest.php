<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Checkout request validation.
 *
 * Prices, quantities, and totals are NEVER accepted from the client —
 * Catalog prices and Cart quantities are authoritative.
 */
class CheckoutRequest extends FormRequest
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
            'shipping_address_id' => [
                'required',
                'integer',
                'exists:addresses,id',
                // Ownership is enforced in CheckoutAction (application-layer
                // scoping; RLS is not part of this baseline).
                function (string $attribute, mixed $value, \Closure $fail): void {
                    $owned = \App\Modules\Customer\Infrastructure\Persistence\Models\Address::query()
                        ->where('user_id', $this->user()->id)
                        ->whereKey((int) $value)
                        ->exists();

                    if (! $owned) {
                        $fail('The selected shipping address does not belong to you.');
                    }
                },
            ],
            'billing_address_id' => [
                'nullable',
                'integer',
                'exists:addresses,id',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $owned = \App\Modules\Customer\Infrastructure\Persistence\Models\Address::query()
                        ->where('user_id', $this->user()->id)
                        ->whereKey((int) $value)
                        ->exists();

                    if (! $owned) {
                        $fail('The selected billing address does not belong to you.');
                    }
                },
            ],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
