<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Http\Resources;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class IdentityResource extends JsonResource
{
    /**
     * Transform the user model into a safe public array representation.
     *
     * Never exposes password_hash, token_hash, or internal BIGSERIAL id.
     */
    public function toArray(Request $request): array
    {
        $profile = $this->customerProfile;

        return [
            'public_id' => $this->public_id,
            'email' => $this->email,
            'phone' => $this->phone,
            'role' => $this->role?->name ?? 'customer',
            'is_email_verified' => (bool) $this->is_email_verified,
            'created_at' => $this->created_at?->format(DateTimeInterface::ATOM),
            'profile' => $profile ? [
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'avatar_url' => $profile->avatar_url,
            ] : null,
        ];
    }
}
