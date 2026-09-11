<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * App\Modules\Identity\Infrastructure\Persistence\Models\Permission
 *
 * Maps to PostgreSQL baseline table: permissions
 */
class Permission extends Model
{
    use HasFactory;

    protected $table = 'permissions';

    public $timestamps = false;

    protected $fillable = [
        'code',
        'description',
    ];

    /**
     * Relationship: Permission belongs to many Roles via role_permissions pivot.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_permissions',
            'permission_id',
            'role_id'
        );
    }
}
