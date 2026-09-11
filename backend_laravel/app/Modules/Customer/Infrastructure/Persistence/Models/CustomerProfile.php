<?php

declare(strict_types=1);

namespace App\Modules\Customer\Infrastructure\Persistence\Models;

use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerProfile Model
 *
 * Maps to authoritative PostgreSQL baseline table: customer_profiles
 * 1:1 relationship with users. Extends authenticated user with profile data.
 */
class CustomerProfile extends Model
{
    protected $table = 'customer_profiles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'date_of_birth',
        'gender',
        'avatar_url',
        'preferences',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'preferences'   => 'array',
            'created_at'    => 'datetime',
            'updated_at'    => 'datetime',
        ];
    }

    /**
     * Relationship: CustomerProfile belongs to exactly one User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
