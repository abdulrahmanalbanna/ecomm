<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Append-only lifecycle event. Exposed read-only (no update/delete routes).
 */
class OrderEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'           => (int) $this->id,
            'from_status'  => $this->from_status,
            'to_status'    => $this->to_status,
            'triggered_by' => $this->triggered_by !== null ? (int) $this->triggered_by : null,
            'note'         => $this->note,
            'metadata'     => (object) $this->metadata,
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
