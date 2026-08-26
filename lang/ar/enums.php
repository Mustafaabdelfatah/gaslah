<?php

return [
    'active_type' => [
        'active' => 'نشط',
        'in_active' => 'غير نشط',
    ],
    'otp_type' => [
        'login' => 'تسجيل الدخول',
        'reset_password' => 'إعادة تعيين كلمة المرور',
        'verify_email' => 'التحقق من البريد الإلكتروني',
    ],
    'report_chart_type' => [
        'high_chart' => 'مخطط عالي',
    ],
    'report_page_type' => [
        'user' => 'المستخدمين',
    ],
    'setting_type' => [
        'text' => 'نص',
        'file' => 'ملف',
        'image_uploader' => 'محمل الصور',
    ],
    'user_gender' => [
        'male' => 'ذكر',
        'female' => 'أنثى',
    ],
    'notification_group' => [
        'global' => 'عام',
    ],
    'staff_role' => [
        'super_admin' => 'مدير عام',
        'branch_manager' => 'مدير فرع',
        'cashier' => 'كاشير',
        'reception' => 'استقبال',
    ],
    'platform_role' => [
        'owner' => 'مالك المنصة',
        'support' => 'دعم',
        'sales' => 'مبيعات',
        'finance' => 'مالية',
        'viewer' => 'مشاهدة فقط',
    ],
    // Nested to match the dotted permission keys, which the translator reads as a path.
    'staff_permission' => [
        'users' => ['manage' => 'إدارة المستخدمين'],
        'settings' => ['manage' => 'إعدادات المنشأة'],
        'pos' => ['checkout' => 'إتمام البيع'],
        'orders' => ['manage' => 'إدارة الطلبات'],
        'customers' => ['manage' => 'إدارة العملاء'],
        'catalog' => [
            'manage' => 'تعديل الكتالوج والأسعار',
            'read' => 'عرض الكتالوج',
            'manage_codes' => 'تعديل رموز المنتجات',
        ],
        'shifts' => ['manage' => 'إدارة الورديات'],
        'reports' => ['view' => 'عرض التقارير'],
        'accounting' => ['view' => 'عرض المحاسبة'],
    ],
    'platform_permission' => [
        'manage_tenants' => 'إدارة المنشآت',
        'manage_subscriptions' => 'إدارة الاشتراكات',
        'manage_plans' => 'إدارة الخطط',
        'manage_admins' => 'إدارة مستخدمي الأدمن',
        'manage_crm' => 'إدارة المتابعة',
        'manage_leads' => 'إدارة العملاء المحتملين',
        'manage_accounting' => 'إدارة المحاسبة',
        'manage_support' => 'إدارة الدعم الفني',
        'manage_marketing' => 'إدارة التسويق',
        'manage_announcements' => 'إدارة الإعلانات',
        'manage_config' => 'إعدادات النظام',
        'view_finance' => 'عرض المالية',
        'manage_partners' => 'إدارة الشركاء',
        'manage_whatsapp' => 'إدارة رسائل واتساب',
        'manage_payouts' => 'إدارة التسويات البنكية',
    ],
    'feature_category' => [
        'core' => 'أساسية',
        'operations' => 'العمليات',
        'growth' => 'النمو',
        'finance' => 'المالية والرؤى',
    ],
    'token_kind' => [
        'staff' => 'موظف',
        'platform' => 'أدمن المنصة',
        'customer' => 'عميل',
        'supplier' => 'مورّد',
        'driver' => 'مندوب',
        'affiliate' => 'مسوّق',
        'pos_otp' => 'إثبات موافقة العميل',
    ],
    'security_action' => [
        'login_ok' => 'دخول ناجح',
        'login_failed' => 'محاولة دخول فاشلة',
    ],
    'security_surface' => [
        'staff' => 'الموظفين',
        'admin' => 'أدمن المنصة',
        'customer' => 'بوابة العميل',
        'supplier' => 'بوابة المورّد',
        'driver' => 'تطبيق المندوب',
        'affiliate' => 'بوابة المسوّق',
    ],
    'token_revocation_reason' => [
        'logout' => 'تسجيل خروج',
        'forced' => 'إبطال قسري',
        'reserve' => 'حرق إثبات الموافقة',
        'impersonation_stop' => 'إيقاف الانتحال',
    ],
];
