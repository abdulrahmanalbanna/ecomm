<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnStockRequest extends FormRequest
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
            'quantity' => ['required', 'integer', 'min:1'],
            'note'     => ['nullable', 'string', 'max:1000'],
            'order_id' => ['nullable', 'integer'],
        ];
    }
}
