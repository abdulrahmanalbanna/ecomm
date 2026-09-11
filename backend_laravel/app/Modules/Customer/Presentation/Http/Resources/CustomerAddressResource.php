<?php

declare(strict_types=1);

namespace App\Modules\Customer\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'label'          => $this->label,
            'recipient_name' => $this->recipient_name,
            'phone'          => $this->phone,
            'line1'          => $this->line1,
            'line2'          => $this->line2,
            'city'           => $this->city,
            'state'          => $this->state,
            'postal_code'    => $this->postal_code,
            'country_code'   => $this->country_code,
            'is_default'     => $this->is_default,
            'created_at'     => $this->created_at?->toAtomString(),
            'updated_at'     => $this->updated_at?->toAtomString(),
        ];
    }
}
