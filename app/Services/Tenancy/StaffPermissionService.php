<?php

namespace App\Services\Tenancy;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\User;
use App\Models\UserPermissionOverride;

/**
 * Resolves what a staff member is actually allowed to do inside their organization.
 *
 * Roles are always read back from the branch memberships rather than trusted from
 * the mirror column, so a demotion takes effect on the caller's very next request.
 */
class StaffPermissionService
{
    /**
     * The permissions in force for this user.
     *
     * An explicit override replaces the role defaults outright rather than extending
     * them — including an override that grants nothing, which is a deliberate way to
     * strip an account back without changing its role.
     *
     * @return array<int, string>
     */
    public function effectiveFor(User $user): array
    {
        $override = $this->overrideFor($user);

        if ($override !== null) {
            return $override->items
                ->map(fn ($item) => $item->permission->value)
                ->values()
                ->all();
        }

        return $this->highestRoleFor($user)?->permissionValues() ?? [];
    }

    public function has(User $user, StaffPermissionEnum|string $permission): bool
    {
        $permission = $permission instanceof StaffPermissionEnum ? $permission->value : $permission;

        return in_array($permission, $this->effectiveFor($user), true);
    }

    /**
     * Refuse the request unless the permission is held.
     */
    public function require(User $user, StaffPermissionEnum|string $permission): void
    {
        if (! $this->has($user, $permission)) {
            abort(403, __('api.unauthorized'));
        }
    }

    /**
     * The role held in one specific branch.
     */
    public function roleForBranch(User $user, int $branchId): ?StaffRoleEnum
    {
        return $user->userBranches()
            ->where('branch_id', $branchId)
            ->value('role');
    }

    /**
     * The highest role held across the user's branches, read live.
     */
    public function highestRoleFor(User $user, ?int $organizationId = null): ?StaffRoleEnum
    {
        $roles = $user->userBranches()
            ->when(
                $organizationId !== null,
                fn ($query) => $query->whereHas(
                    'branch',
                    fn ($branch) => $branch->where('organization_id', $organizationId)
                )
            )
            ->pluck('role')
            ->all();

        return StaffRoleEnum::highest($roles);
    }

    /**
     * Refresh the mirror column on the user after their memberships change.
     *
     * Kept quiet: this is bookkeeping derived from another write, not an edit worth
     * its own activity log entry.
     */
    public function syncDerivedRole(User $user): void
    {
        $role = $this->highestRoleFor($user);

        if ($user->role === $role) {
            return;
        }

        $user->role = $role;
        $user->saveQuietly();
    }

    /**
     * Whether the caller may grant the given role.
     *
     * Nobody hands out a role above their own rank; without this a branch manager
     * could promote themselves to a general manager.
     */
    public function canAssignRole(User $actor, StaffRoleEnum $role, ?int $organizationId = null): bool
    {
        $actorRole = $this->highestRoleFor($actor, $organizationId);

        return $actorRole !== null && $role->rank() <= $actorRole->rank();
    }

    private function overrideFor(User $user): ?UserPermissionOverride
    {
        return UserPermissionOverride::query()
            ->with('items')
            ->where('user_id', $user->getKey())
            ->first();
    }
}
