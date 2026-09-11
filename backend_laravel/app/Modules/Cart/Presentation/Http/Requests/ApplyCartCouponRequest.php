<?php

declare(strict_types=1);

namespace App\Modules\Cart\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyCartCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'coupon_code' => ['required', 'string', 'max:50'],
        ];
    }
}
