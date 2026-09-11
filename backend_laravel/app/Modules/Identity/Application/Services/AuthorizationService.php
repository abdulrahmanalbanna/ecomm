<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Services;

use App\Modules\Identity\Infrastructure\Persistence\Models\Role;
use App\Modules\Identity\Infrastructure\Persistence\Models\User;
use Illuminate\Support\Facades\DB;

class AuthorizationService
{
    /**
     * Request-level cache for evaluated permission checks.
     * Format: [user_id => [permission_code => bool]]
     *
     * @var array<int|string, array<string, bool>>
     */
    protected array $permissionCache = [];

    /**
     * Request-level cache for user role names.
     * Format: [user_id => role_name]
     *
     * @var array<int|string, string|null>
     */
    protected array $roleNameCache = [];

    /**
     * Check if the user has ANY of the specified roles (comma-separated or array).
     *
     * @param User $user
     * @param string|array<int, string> $roles
     */
    public function hasRole(User $user, string|array $roles): bool
    {
        $roleList = is_array($roles)
            ? $roles
            : array_map('trim', explode(',', $roles));

        $roleList = array_filter($roleList);

        if (empty($roleList)) {
            return false;
        }

        $userRoleName = $this->getUserRoleName($user);

        if ($userRoleName === null) {
            return false;
        }

        return in_array($userRoleName, $roleList, true);
    }

    /**
     * Determine if a user has a specific permission code.
     * Uses index-aware exists() query with request-level caching.
     */
    public function hasPermission(User $user, string $permissionCode): bool
    {
        if (isset($this->permissionCache[$user->id][$permissionCode])) {
            return $this->permissionCache[$user->id][$permissionCode];
        }

        if ($user->relationLoaded('role') && $user->role && $user->role->relationLoaded('permissions')) {
            $hasPerm = $user->role->permissions->contains('code', $permissionCode);
            $this->permissionCache[$user->id][$permissionCode] = $hasPerm;
            return $hasPerm;
        }

        // Index-aware existence query:
        // Uses primary key / leading index on role_permissions (role_id)
        // and unique index on permissions (code).
        $hasPerm = DB::table('role_permissions', 'rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', '=', $user->role_id)
            ->where('p.code', '=', $permissionCode)
            ->exists();

        $this->permissionCache[$user->id][$permissionCode] = $hasPerm;

        return $hasPerm;
    }

    /**
     * Resolve all permission codes assigned to the user's role.
     *
     * @return array<int, string>
     */
    public function getUserPermissions(User $user): array
    {
        if ($user->relationLoaded('role') && $user->role && $user->role->relationLoaded('permissions')) {
            return $user->role->permissions->pluck('code')->all();
        }

        return DB::table('role_permissions', 'rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', '=', $user->role_id)
            ->pluck('p.code')
            ->all();
    }

    /**
     * Get user role name (cached per user ID).
     */
    public function getUserRoleName(User $user): ?string
    {
        if (array_key_exists($user->id, $this->roleNameCache)) {
            return $this->roleNameCache[$user->id];
        }

        if ($user->relationLoaded('role') && $user->role !== null) {
            $roleName = $user->role->name;
        } else {
            $roleName = Role::query()->where('id', $user->role_id)->value('name');
        }

        $this->roleNameCache[$user->id] = $roleName;

        return $roleName;
    }
}
