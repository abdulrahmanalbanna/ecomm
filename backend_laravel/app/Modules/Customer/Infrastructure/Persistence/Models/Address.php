<?php

declare(strict_types=1);

namespace App\Modules\Customer\Infrastructure\Persistence\Models;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Address Model
 *
 * Maps to authoritative PostgreSQL baseline table: addresses
 * Customer address book. Orders retain independent JSON snapshots.
 * Hard delete only — no deleted_at column in baseline schema.
 */
class Address extends Model
{
    protected $table = 'addresses';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country_code',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Relationship: Address belongs to exactly one User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
