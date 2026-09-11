<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * App\Modules\Identity\Infrastructure\Persistence\Models\Session
 *
 * Maps to PostgreSQL baseline table: sessions
 */
class Session extends Model
{
    use HasFactory;

    protected $table = 'sessions';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'token_hash',
        'ip_address',
        'user_agent',
        'expires_at',
        'created_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Relationship: Session belongs to a User.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Scope to find active (unexpired and unrevoked) sessions.
     */
    public function scopeActive($query)
    {
        return $query->whereNull('revoked_at')
                     ->where('expires_at', '>', now());
    }
}
