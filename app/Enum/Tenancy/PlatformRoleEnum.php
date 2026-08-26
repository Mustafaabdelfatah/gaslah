<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Role a platform (Gaslah operator) administrator holds.
 *
 * A null platform_role on an existing platform user is treated as Owner, so legacy
 * accounts keep full access until a narrower role is assigned deliberately.
 */
enum PlatformRoleEnum: string
{
    use EnumMethods;

    case Owner = 'owner';
    case Support = 'support';
    case Sales = 'sales';
    case Finance = 'finance';
    case Viewer = 'viewer';

    /**
     * Owner is unrestricted; every permission check passes without consulting the
     * granted permission rows.
     */
    public function bypassesPermissions(): bool
    {
        return $this === self::Owner;
    }

    /**
     * Default permission preset applied when this role is assigned.
     *
     * @return array<int, PlatformPermissionEnum>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => PlatformPermissionEnum::cases(),
            self::Support => [
                PlatformPermissionEnum::ManageTenants,
                PlatformPermissionEnum::ManageCrm,
                PlatformPermissionEnum::ManageSupport,
            ],
            self::Sales => [
                PlatformPermissionEnum::ManageCrm,
                PlatformPermissionEnum::ManageLeads,
                PlatformPermissionEnum::ManageMarketing,
                PlatformPermissionEnum::ViewFinance,
            ],
            self::Finance => [
                PlatformPermissionEnum::ViewFinance,
                PlatformPermissionEnum::ManageSubscriptions,
                PlatformPermissionEnum::ManageAccounting,
                PlatformPermissionEnum::ManagePayouts,
            ],
            self::Viewer => [],
        };
    }

    /**
     * @return array<int, string>
     */
    public function permissionValues(): array
    {
        return array_map(static fn (PlatformPermissionEnum $permission) => $permission->value, $this->permissions());
    }
}
