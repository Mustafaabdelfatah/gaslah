<?php

namespace Database\Seeders;

use App\Enum\Tenancy\FeatureCategoryEnum;
use App\Models\Feature;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class FeatureSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The gateable capability catalogue.
     *
     * Core entries make up the base product and are never withheld; everything else
     * is what a plan may grant or an operator may switch off per organization.
     *
     * @var array<string, array{0: string, 1: string, 2: FeatureCategoryEnum}>
     */
    private const FEATURES = [
        'pos' => ['نقطة البيع', 'Point of Sale', FeatureCategoryEnum::Core],
        'orders' => ['الطلبات', 'Orders', FeatureCategoryEnum::Core],
        'customers' => ['العملاء', 'Customers', FeatureCategoryEnum::Core],
        'catalog' => ['الكتالوج والأسعار', 'Catalog & Pricing', FeatureCategoryEnum::Core],
        'shift' => ['الورديات', 'Shifts', FeatureCategoryEnum::Core],
        'settings' => ['الإعدادات', 'Settings', FeatureCategoryEnum::Core],

        'delivery' => ['التوصيل', 'Delivery', FeatureCategoryEnum::Operations],
        'inventory' => ['المخزون والموردون', 'Inventory & Suppliers', FeatureCategoryEnum::Operations],
        'contracts' => ['العقود', 'Contracts', FeatureCategoryEnum::Operations],

        'loyalty' => ['الولاء والنقاط', 'Loyalty & Points', FeatureCategoryEnum::Growth],
        'subscriptions' => ['اشتراكات العملاء', 'Customer Subscriptions', FeatureCategoryEnum::Growth],
        'portal' => ['بوابة العملاء', 'Customer Portal', FeatureCategoryEnum::Growth],
        'portal_offers' => ['عروض البوابة', 'Portal Offers', FeatureCategoryEnum::Growth],
        'messaging' => ['الرسائل', 'Messaging', FeatureCategoryEnum::Growth],
        'branding' => ['الهوية والعلامة', 'Branding', FeatureCategoryEnum::Growth],
        'supplier_market' => ['سوق الموردين', 'Supplier Market', FeatureCategoryEnum::Growth],

        'analytics' => ['التحليلات المتقدمة', 'Advanced Analytics', FeatureCategoryEnum::Finance],
        'reports_export' => ['تصدير التقارير', 'Reports Export', FeatureCategoryEnum::Finance],
    ];

    public function run(): void
    {
        $sortOrder = 0;

        foreach (self::FEATURES as $key => [$nameAr, $nameEn, $category]) {
            Feature::query()->updateOrCreate(
                ['key' => $key],
                [
                    'name' => ['ar' => $nameAr, 'en' => $nameEn],
                    'category' => $category->value,
                    'is_core' => $category === FeatureCategoryEnum::Core,
                    'sort_order' => $sortOrder += 10,
                ]
            );
        }
    }
}
