<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The complete catalogue of fine-grained permissions a branch staff member may hold.
 *
 * These are distinct from the Spatie permissions used by the platform surface: they are
 * resolved per organization through the staff role defaults plus an optional per-user
 * override, and are checked with StaffPermissionService.
 */
enum StaffPermissionEnum: string
{
    use EnumMethods;

    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case PosCheckout = 'pos.checkout';
    case OrdersManage = 'orders.manage';
    case CustomersManage = 'customers.manage';
    case CatalogManage = 'catalog.manage';
    case CatalogRead = 'catalog.read';
    case CatalogManageCodes = 'catalog.manage_codes';
    case ShiftsManage = 'shifts.manage';
    case ReportsView = 'reports.view';
    case AccountingView = 'accounting.view';
}
