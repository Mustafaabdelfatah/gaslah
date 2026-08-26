<?php

namespace App\Services\Tenancy;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\User;
use App\Models\UserPlatformPermission;

/**
 * Access rules for the platform (Gaslah operator) console.
 *
 * Every platform role carries the operator flag, including Viewer, so the flag alone
 * is never enough to authorise anything sensitive. Checks here read the live role and
 * grants, which is what makes a revoked administrator lose access at once.
 */
class PlatformAccessService
{
    public function isPlatformAdmin(?User $user): bool
    {
        return $user instanceof User && $user->is_active && $user->isPlatformAdmin();
    }

    /**
     * The role in force, with a missing role treated as Owner.
     */
    public function role(?User $user): ?PlatformRoleEnum
    {
        if (! $this->isPlatformAdmin($user)) {
            return null;
        }

        return $user->platform_role ?? PlatformRoleEnum::Owner;
    }

    public function isOwner(?User $user): bool
    {
        return $this->role($user) === PlatformRoleEnum::Owner;
    }

    /**
     * @return array<int, string>
     */
    public function permissions(?User $user): array
    {
        $role = $this->role($user);

        if ($role === null) {
            return [];
        }

        if ($role->bypassesPermissions()) {
            return PlatformPermissionEnum::values();
        }

        return UserPlatformPermission::query()
            ->where('user_id', $user->getKey())
            ->pluck('permission')
            ->map(fn ($permission) => $permission instanceof PlatformPermissionEnum ? $permission->value : $permission)
            ->all();
    }

    public function has(?User $user, PlatformPermissionEnum|string $permission): bool
    {
        $permission = $permission instanceof PlatformPermissionEnum ? $permission->value : $permission;

        return in_array($permission, $this->permissions($user), true);
    }

    public function requirePlatformAdmin(?User $user): void
    {
        if (! $this->isPlatformAdmin($user)) {
            abort(403, __('api.unauthorized'));
        }
    }

    public function requirePermission(?User $user, PlatformPermissionEnum|string $permission): void
    {
        $this->requirePlatformAdmin($user);

        if (! $this->has($user, $permission)) {
            abort(403, __('api.unauthorized'));
        }
    }

    /**
     * Guard the operations that reach across tenants.
     *
     * Impersonation and anything else that mints tenant authority is Owner-only: a
     * narrower role holding manage_tenants must not be able to become a general
     * manager inside a customer's business.
     */
    public function requireOwner(?User $user): void
    {
        $this->requirePlatformAdmin($user);

        if (! $this->isOwner($user)) {
            abort(403, __('api.unauthorized'));
        }
    }
}
