<?php

declare(strict_types=1);

namespace App\Modules\Settings\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * BusinessSetting
 *
 * Eloquent representation of the `business_settings` key/value store
 * (see database/sql/002_identity_auth.sql).
 *
 * Only rows with `is_public = true` AND `is_active = true` may ever be
 * exposed through the public settings API.
 */
class BusinessSetting extends Model
{
    protected $table = 'business_settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'is_public',
        'is_active',
        'description',
    ];

    protected $casts = [
        'is_public' => 'boolean',
        'is_active' => 'boolean',
        // NOTE: `value` is intentionally NOT cast. The column stores raw
        // strings for every type (string | number | boolean | json | array)
        // and SettingsService::cast() handles conversion based on `type`.
        // Casting `value` to `array` here breaks plain strings ('SAR' is not
        // valid JSON) and coerces 'false'/'15.00' to bool/float, which then
        // crashes SettingsService::cast(?string ...) with a 500.
    ];

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true)->where('is_active', true);
    }
}
