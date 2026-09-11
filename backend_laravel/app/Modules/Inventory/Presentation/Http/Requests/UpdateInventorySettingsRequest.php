<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInventorySettingsRequest extends FormRequest
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
            'reorder_point'    => ['sometimes', 'integer', 'min:0'],
            'reorder_quantity' => ['sometimes', 'integer', 'min:1'],
            'allow_backorder'  => ['sometimes', 'boolean'],
        ];
    }
}
