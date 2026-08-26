<?php

return [
    'active_type' => [
        'active' => 'Active',
        'in_active' => 'Inactive',
    ],
    'otp_type' => [
        'login' => 'Login',
        'reset_password' => 'Password Reset',
        'verify_email' => 'Email Verification',
    ],
    'report_chart_type' => [
        'high_chart' => 'High Chart',
    ],
    'report_page_type' => [
        'user' => 'Users',
    ],
    'setting_type' => [
        'text' => 'Text',
        'file' => 'File',
        'image_uploader' => 'Image Uploader',
    ],
    'user_gender' => [
        'male' => 'Male',
        'female' => 'Female',
    ],
    'notification_group' => [
        'global' => 'Global',
    ],
    'staff_role' => [
        'super_admin' => 'General Manager',
        'branch_manager' => 'Branch Manager',
        'cashier' => 'Cashier',
        'reception' => 'Reception',
    ],
    'platform_role' => [
        'owner' => 'Platform Owner',
        'support' => 'Support',
        'sales' => 'Sales',
        'finance' => 'Finance',
        'viewer' => 'Viewer',
    ],
    // Nested to match the dotted permission keys, which the translator reads as a path.
    'staff_permission' => [
        'users' => ['manage' => 'Manage Users'],
        'settings' => ['manage' => 'Organization Settings'],
        'pos' => ['checkout' => 'Point of Sale Checkout'],
        'orders' => ['manage' => 'Manage Orders'],
        'customers' => ['manage' => 'Manage Customers'],
        'catalog' => [
            'manage' => 'Manage Catalog and Pricing',
            'read' => 'View Catalog',
            'manage_codes' => 'Manage Product Codes',
        ],
        'shifts' => ['manage' => 'Manage Shifts'],
        'reports' => ['view' => 'View Reports'],
        'accounting' => ['view' => 'View Accounting'],
    ],
    'platform_permission' => [
        'manage_tenants' => 'Manage Organizations',
        'manage_subscriptions' => 'Manage Subscriptions',
        'manage_plans' => 'Manage Plans',
        'manage_admins' => 'Manage Admin Users',
        'manage_crm' => 'Manage CRM',
        'manage_leads' => 'Manage Leads',
        'manage_accounting' => 'Manage Accounting',
        'manage_support' => 'Manage Support',
        'manage_marketing' => 'Manage Marketing',
        'manage_announcements' => 'Manage Announcements',
        'manage_config' => 'System Configuration',
        'view_finance' => 'View Finance',
        'manage_partners' => 'Manage Partners',
        'manage_whatsapp' => 'Manage WhatsApp Messaging',
        'manage_payouts' => 'Manage Bank Payouts',
    ],
    'feature_category' => [
        'core' => 'Core',
        'operations' => 'Operations',
        'growth' => 'Growth',
        'finance' => 'Finance & Insights',
    ],
    'token_kind' => [
        'staff' => 'Staff',
        'platform' => 'Platform Admin',
        'customer' => 'Customer',
        'supplier' => 'Supplier',
        'driver' => 'Driver',
        'affiliate' => 'Affiliate',
        'pos_otp' => 'Customer Consent Proof',
    ],
    'security_action' => [
        'login_ok' => 'Successful Sign-in',
        'login_failed' => 'Failed Sign-in',
    ],
    'security_surface' => [
        'staff' => 'Staff',
        'admin' => 'Platform Admin',
        'customer' => 'Customer Portal',
        'supplier' => 'Supplier Portal',
        'driver' => 'Driver App',
        'affiliate' => 'Affiliate Portal',
    ],
    'token_revocation_reason' => [
        'logout' => 'Logout',
        'forced' => 'Forced Revocation',
        'reserve' => 'Consent Proof Burned',
        'impersonation_stop' => 'Impersonation Stopped',
    ],
];
