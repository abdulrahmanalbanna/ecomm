<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AttributeOption Model
 *
 * Allowed machine values and display labels for select / multiselect attribute definitions.
 */
class AttributeOption extends Model
{
    protected $table = 'attribute_options';

    protected $fillable = [
        'attribute_id',
        'value',
        'display_name',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function attribute(): BelongsTo
    {
        return $this->belongsTo(AttributeDefinition::class, 'attribute_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
