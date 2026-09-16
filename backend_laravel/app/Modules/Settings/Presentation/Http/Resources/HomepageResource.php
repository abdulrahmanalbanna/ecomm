<?php

declare(strict_types=1);

namespace App\Modules\Settings\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stable, frontend-friendly public homepage payload.
 *
 * The SettingsService performs row-level visibility filtering, JSON decoding,
 * item activation filtering, and normalization before this resource is built.
 */
final class HomepageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return $payload;
    }
}
