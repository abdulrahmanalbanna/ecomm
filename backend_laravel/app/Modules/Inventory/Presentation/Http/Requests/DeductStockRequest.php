<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeductStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var \App\Modules\Identity\Infrastructure\Persistence\Models\User|null $user */
        $user = $this->user();

        if (! $user) {
            return true;
        }

        return app(\App\Modules\Identity\Application\Services\AuthorizationService::class)->hasPermission($user, 'inventory.adjust');
    }

    public function rules(): array
    {
        return [
            'quantity'       => ['required_without:reservation_id', 'nullable', 'integer', 'min:1'],
            'order_id'       => ['nullable', 'integer'],
            'reservation_id' => ['nullable', 'integer', 'exists:inventory_reservations,id'],
        ];
    }
}
