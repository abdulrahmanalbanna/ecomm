<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Requests;

use App\Modules\Orders\Domain\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionOrderStatusRequest extends FormRequest
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
            'status' => ['required', 'string', Rule::in(OrderStatus::all())],
            'note'   => ['nullable', 'string', 'max:1000'],
        ];
    }
}
