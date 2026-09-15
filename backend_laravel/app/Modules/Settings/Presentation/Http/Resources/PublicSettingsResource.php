<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PublicSettingsResource
 *
 * Shapes the storefront settings payload. The controller already builds the
 * array via SettingsService::toPublicPayload(), so this resource only
 * guarantees a stable envelope: { data: { ... } }.
 */
class PublicSettingsResource extends JsonResource
{
    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return $payload;
    }
}
