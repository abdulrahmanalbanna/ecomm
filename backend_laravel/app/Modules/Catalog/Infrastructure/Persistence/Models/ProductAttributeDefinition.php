<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProductAttributeDefinition Model
 *
 * Configures which global attributes apply to a product and whether they are required.
 * Handles the [product_id, attribute_id] composite primary key explicitly.
 */
class ProductAttributeDefinition extends Model
{
    protected $table = 'product_attribute_definitions';

    public $incrementing = false;

    protected $primaryKey = ['product_id', 'attribute_id'];

    protected $fillable = [
        'product_id',
        'attribute_id',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'sort_order'  => 'integer',
        'created_at'  => 'datetime',
        'updated_at'  => 'datetime',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_id');
    }

    /**
     * Set composite primary key for save/update/delete operations.
     */
    protected function setKeysForSaveQuery($query)
    {
        return $query->where('product_id', $this->getAttribute('product_id'))
            ->where('attribute_id', $this->getAttribute('attribute_id'));
    }
}
