<?php

namespace App\Services\Tenancy;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserBranch;
use App\Models\UserPermissionOverride;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organization's own staff: who works in which branch, in what role, and with
 * which permissions.
 *
 * Two rules run through everything here. Nobody may grant a role above their own rank,
 * which is what stops in-organization privilege escalation; and an account is only
 * reachable at all if every branch it belongs to is one of the caller's own.
 */
class OrgUserService
{
    public function __construct(private readonly StaffPermissionService $permissions) {}

    /**
     * Staff of the organization, newest first.
     */
    public function query(int $organizationId, ?string $search): Builder
    {
        return User::query()
            ->whereHas('userBranches.branch', fn ($q) => $q->where('organization_id', $organizationId))
            ->when($search, fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%"),
            ))
            ->with(['userBranches.branch:id,name', 'permissionOverride.items'])
            ->orderByDesc('id');
    }

    /**
     * A staff member of this organization, or a 404 that does not confirm the account
     * exists somewhere else.
     */
    public function findInOrganization(int $organizationId, int $userId): User
    {
        return $this->query($organizationId, null)
            ->whereKey($userId)
            ->firstOr(fn () => abort(Response::HTTP_NOT_FOUND, __('api.not_found')));
    }

    /**
     * Hire a staff member into one or more branches.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, int $organizationId, array $data): User
    {
        $memberships = $this->resolveMemberships($actor, $organizationId, $data['branches']);

        return DB::transaction(function () use ($data, $memberships) {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'],
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->syncMemberships($user, $memberships);
            $this->syncOverride($user, $data['permissions'] ?? null);

            return $user;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $actor, User $user, int $organizationId, array $data): User
    {
        $this->assertMayEdit($actor, $user, $organizationId);

        $memberships = array_key_exists('branches', $data)
            ? $this->resolveMemberships($actor, $organizationId, $data['branches'])
            : null;

        // Locking yourself out is never the intent of an edit, so it is refused
        // rather than quietly applied.
        if ($actor->is($user) && array_key_exists('is_active', $data) && ! $data['is_active']) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.org_user_cannot_deactivate_self'));
        }

        return DB::transaction(function () use ($user, $data, $memberships) {
            $user->fill(array_filter([
                'name' => $data['name'] ?? null,
                'phone' => $data['phone'] ?? null,
                'password' => $data['password'] ?? null,
            ], static fn ($value) => $value !== null));

            if (array_key_exists('is_active', $data)) {
                $user->is_active = $data['is_active'];
            }

            $user->save();

            if ($memberships !== null) {
                $this->syncMemberships($user, $memberships);
            }

            // Only touch the override when the caller said something about it: an
            // absent key means "leave it alone", an explicit null means "clear it".
            if (array_key_exists('permissions', $data)) {
                $this->syncOverride($user, $data['permissions']);
            }

            return $user;
        });
    }

    /**
     * Suspend an account without deleting it — history stays attributable.
     */
    public function deactivate(User $actor, User $user, int $organizationId): User
    {
        return $this->update($actor, $user, $organizationId, ['is_active' => false]);
    }

    /**
     * The roles this caller may hand out, each with the permissions it grants, plus
     * the whole permission catalogue grouped by the area it governs.
     *
     * @return array<string, mixed>
     */
    public function rolesCatalogue(User $actor, int $organizationId): array
    {
        $actorRole = $this->permissions->highestRoleFor($actor, $organizationId);
        $assignable = $actorRole?->assignableRoles() ?? [];

        return [
            'roles' => array_map(fn (StaffRoleEnum $role) => [
                'value' => $role->value,
                'label' => __("api.staff_role_{$role->value}"),
                'permissions' => $role->permissionValues(),
            ], $assignable),

            'permission_catalog' => $this->permissionCatalogue(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate the requested branch/role pairs against the caller's own reach and rank.
     *
     * @param  array<int, array{branch_id: int, role: string}>  $rows
     * @return array<int, array{branch_id: int, role: StaffRoleEnum}>
     */
    private function resolveMemberships(User $actor, int $organizationId, array $rows): array
    {
        $branchIds = Branch::query()
            ->where('organization_id', $organizationId)
            ->pluck('id')
            ->all();

        $resolved = [];

        foreach ($rows as $row) {
            $branchId = (int) $row['branch_id'];

            if (! in_array($branchId, $branchIds, true)) {
                abort(Response::HTTP_NOT_FOUND, __('api.not_found'));
            }

            $role = StaffRoleEnum::from($row['role']);

            if (! $this->permissions->canAssignRole($actor, $role, $organizationId)) {
                abort(Response::HTTP_FORBIDDEN, __('api.org_user_role_above_your_own'));
            }

            // Last write wins per branch, so a duplicated branch is not an error.
            $resolved[$branchId] = ['branch_id' => $branchId, 'role' => $role];
        }

        if ($resolved === []) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.org_user_needs_a_branch'));
        }

        return array_values($resolved);
    }

    /**
     * @param  array<int, array{branch_id: int, role: StaffRoleEnum}>  $memberships
     */
    private function syncMemberships(User $user, array $memberships): void
    {
        $keep = [];

        foreach ($memberships as $membership) {
            $row = UserBranch::query()->updateOrCreate(
                ['user_id' => $user->getKey(), 'branch_id' => $membership['branch_id']],
                ['role' => $membership['role']->value],
            );

            $keep[] = $row->getKey();
        }

        UserBranch::query()
            ->where('user_id', $user->getKey())
            ->whereNotIn('id', $keep)
            ->delete();

        $user->load('userBranches');
        $this->permissions->syncDerivedRole($user);
    }

    /**
     * Replace, or clear, a staff member's explicit permission set.
     *
     * The row's existence is the signal: an override with no items grants nothing,
     * which is not the same as having none and falling back to the role.
     *
     * @param  array<int, string>|null  $permissions
     */
    private function syncOverride(User $user, ?array $permissions): void
    {
        if ($permissions === null) {
            UserPermissionOverride::query()->where('user_id', $user->getKey())->delete();
            $user->unsetRelation('permissionOverride');

            return;
        }

        $override = UserPermissionOverride::query()->firstOrCreate(['user_id' => $user->getKey()]);
        $override->items()->delete();

        foreach (array_unique($permissions) as $permission) {
            $override->items()->create(['permission' => StaffPermissionEnum::from($permission)->value]);
        }

        $user->load('permissionOverride.items');
    }

    /**
     * Nobody edits an account that outranks them, and the platform's own staff are
     * not a tenant's to manage.
     */
    private function assertMayEdit(User $actor, User $target, int $organizationId): void
    {
        if (app(PlatformAccessService::class)->isPlatformAdmin($target)) {
            abort(Response::HTTP_FORBIDDEN, __('api.org_user_platform_account'));
        }

        if ($actor->is($target)) {
            return;
        }

        $actorRole = $this->permissions->highestRoleFor($actor, $organizationId);
        $targetRole = $this->permissions->highestRoleFor($target, $organizationId);

        if ($actorRole === null || ($targetRole !== null && ! $actorRole->outranks($targetRole) && $actorRole !== $targetRole)) {
            abort(Response::HTTP_FORBIDDEN, __('api.org_user_role_above_your_own'));
        }
    }

    /**
     * The permission catalogue, grouped by the area each permission governs.
     *
     * @return array<int, array{area: string, items: array<int, array{key: string, label: string}>}>
     */
    private function permissionCatalogue(): array
    {
        $groups = [];

        foreach (StaffPermissionEnum::cases() as $permission) {
            $area = explode('.', $permission->value)[0];
            $groups[$area][] = [
                'key' => $permission->value,
                'label' => __("api.staff_permission_{$permission->value}"),
            ];
        }

        return array_map(
            static fn (string $area, array $items) => [
                'area' => __("api.staff_permission_area_{$area}"),
                'items' => $items,
            ],
            array_keys($groups),
            $groups,
        );
    }
}
