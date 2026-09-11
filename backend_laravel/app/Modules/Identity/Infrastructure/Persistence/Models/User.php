<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * App\Modules\Identity\Infrastructure\Persistence\Models\User
 *
 * Maps to PostgreSQL baseline table: users
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    protected $table = 'users';

    /**
     * Mass assignable attributes.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'email',
        'phone',
        'password_hash',
        'role_id',
        'is_active',
        'is_email_verified',
        'last_login_at',
    ];

    /**
     * Attributes hidden from array/JSON serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'id',
    ];

    /**
     * Attribute casting definitions.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_email_verified' => 'boolean',
            'last_login_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    /**
     * Auto-generate public_id UUID when creating a user if not provided.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(static function (User $user): void {
            if (empty($user->public_id)) {
                $user->public_id = (string) Str::uuid();
            }
        });
    }

    /**
     * Override standard Authenticatable method to use password_hash column.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * Relationship: User belongs to a Role.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /**
     * Relationship: User has many active/revoked sessions.
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(Session::class, 'user_id');
    }

    /**
     * Relationship: User has one CustomerProfile.
     */
    public function customerProfile(): HasOne
    {
        return $this->hasOne(CustomerProfile::class, 'user_id');
    }

    /**
     * Relationship: User has many Addresses.
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(\App\Modules\Customer\Infrastructure\Persistence\Models\Address::class, 'user_id');
    }

    /**
     * Relationship: User has one active Cart.
     */
    public function cart(): HasOne
    {
        return $this->hasOne(\App\Modules\Cart\Infrastructure\Persistence\Models\Cart::class, 'user_id');
    }
}
