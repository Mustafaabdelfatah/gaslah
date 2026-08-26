<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Fine-grained permissions for the platform (Gaslah operator) surface.
 *
 * The Owner role bypasses every one of these; all other platform roles are limited
 * to the keys explicitly granted to them.
 */
enum PlatformPermissionEnum: string
{
    use EnumMethods;

    case ManageTenants = 'manage_tenants';
    case ManageSubscriptions = 'manage_subscriptions';
    case ManagePlans = 'manage_plans';
    case ManageAdmins = 'manage_admins';
    case ManageCrm = 'manage_crm';
    case ManageLeads = 'manage_leads';
    case ManageAccounting = 'manage_accounting';
    case ManageSupport = 'manage_support';
    case ManageMarketing = 'manage_marketing';
    case ManageAnnouncements = 'manage_announcements';
    case ManageConfig = 'manage_config';
    case ViewFinance = 'view_finance';
    case ManagePartners = 'manage_partners';
    case ManageWhatsapp = 'manage_whatsapp';
    case ManagePayouts = 'manage_payouts';
}
