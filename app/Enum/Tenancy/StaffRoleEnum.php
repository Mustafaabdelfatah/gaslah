<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Role a user holds inside a single branch.
 *
 * The authoritative value lives on user_branches.role; users.role only mirrors the
 * highest-ranked role across the user's branches so the login payload stays cheap.
 */
enum StaffRoleEnum: string
{
    use EnumMethods;

    case SuperAdmin = 'super_admin';
    case BranchManager = 'branch_manager';
    case Cashier = 'cashier';
    case Reception = 'reception';

    /**
     * Privilege rank. A manager may never grant a role ranked above their own,
     * which is what blocks in-organization privilege escalation.
     */
    public function rank(): int
    {
        return match ($this) {
            self::SuperAdmin => 4,
            self::BranchManager => 3,
            self::Cashier => 2,
            self::Reception => 1,
        };
    }

    public function outranks(self $other): bool
    {
        return $this->rank() > $other->rank();
    }

    /**
     * Permissions granted by default to this role, used whenever the user carries
     * no explicit override.
     *
     * @return array<int, StaffPermissionEnum>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => [
                StaffPermissionEnum::UsersManage,
                StaffPermissionEnum::SettingsManage,
                StaffPermissionEnum::CatalogManage,
                StaffPermissionEnum::CatalogRead,
                StaffPermissionEnum::CatalogManageCodes,
                StaffPermissionEnum::ReportsView,
                StaffPermissionEnum::PosCheckout,
                StaffPermissionEnum::OrdersManage,
                StaffPermissionEnum::CustomersManage,
                StaffPermissionEnum::ShiftsManage,
                StaffPermissionEnum::AccountingView,
            ],
            self::BranchManager => [
                StaffPermissionEnum::UsersManage,
                StaffPermissionEnum::CatalogRead,
                StaffPermissionEnum::CatalogManageCodes,
                StaffPermissionEnum::ReportsView,
                StaffPermissionEnum::PosCheckout,
                StaffPermissionEnum::OrdersManage,
                StaffPermissionEnum::CustomersManage,
                StaffPermissionEnum::ShiftsManage,
            ],
            self::Cashier => [
                StaffPermissionEnum::PosCheckout,
                StaffPermissionEnum::OrdersManage,
                StaffPermissionEnum::CustomersManage,
                StaffPermissionEnum::ShiftsManage,
            ],
            self::Reception => [
                StaffPermissionEnum::OrdersManage,
                StaffPermissionEnum::CustomersManage,
            ],
        };
    }

    /**
     * @return array<int, string>
     */
    public function permissionValues(): array
    {
        return array_map(static fn (StaffPermissionEnum $permission) => $permission->value, $this->permissions());
    }

    /**
     * Roles a caller of this rank is allowed to assign (never above themselves).
     *
     * @return array<int, self>
     */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role) => $role->rank() <= $this->rank()
        ));
    }

    /**
     * Highest-ranked role among the given values, used to derive users.role.
     *
     * @param  array<int, self|string|null>  $roles
     */
    public static function highest(array $roles): ?self
    {
        $resolved = [];

        foreach ($roles as $role) {
            $role = $role instanceof self ? $role : self::tryFrom((string) $role);

            if ($role instanceof self) {
                $resolved[] = $role;
            }
        }

        if ($resolved === []) {
            return null;
        }

        usort($resolved, static fn (self $a, self $b) => $b->rank() <=> $a->rank());

        return $resolved[0];
    }
}
