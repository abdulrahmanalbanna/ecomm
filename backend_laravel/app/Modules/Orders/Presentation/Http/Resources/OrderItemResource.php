<?php

declare(strict_types=1);

namespace App\Modules\Orders\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Immutable line item. Monetary values are exact decimal STRINGS (never
 * floats) so clients can parse them without precision loss.
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => (int) $this->id,
            'variant_id'       => (int) $this->variant_id,
            'sku'              => $this->sku,
            'name'             => $this->name,
            'quantity'         => (int) $this->quantity,
            'unit_price'       => (string) $this->unit_price,
            'total_price'      => (string) $this->total_price,
            'currency'         => 'SAR',
            'product_snapshot' => $this->product_snapshot,
        ];
    }
}
