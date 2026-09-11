<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * AttributeDefinition Model
 *
 * Represents global catalog attribute definitions (text, number, boolean, select, multiselect).
 */
class AttributeDefinition extends Model
{
    protected $table = 'attribute_definitions';

    protected $fillable = [
        'name',
        'display_name',
        'type',
        'unit',
        'is_filterable',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_filterable' => 'boolean',
        'is_active'     => 'boolean',
        'sort_order'    => 'integer',
        'created_at'    => 'datetime',
        'updated_at'    => 'datetime',
    ];

    public function options(): HasMany
    {
        return $this->hasMany(AttributeOption::class, 'attribute_id')->orderBy('sort_order')->orderBy('id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(
            Product::class,
            'product_attribute_definitions',
            'attribute_id',
            'product_id'
        )->withPivot(['is_required', 'sort_order'])->withTimestamps();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
