<?php

declare(strict_types=1);

namespace App\Modules\Customer\Presentation\Http\Resources;

use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Modules\Customer\Infrastructure\Persistence\Models\CustomerProfile
 */
class CustomerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'first_name'    => $this->first_name,
            'last_name'     => $this->last_name,
            'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
            'gender'        => $this->gender,
            'avatar_url'    => $this->avatar_url,
            'preferences'   => $this->preferences,
            'created_at'    => $this->created_at?->format(DateTimeInterface::ATOM),
            'updated_at'    => $this->updated_at?->format(DateTimeInterface::ATOM),
        ];
    }
}
