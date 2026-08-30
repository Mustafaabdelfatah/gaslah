<?php

namespace Database\Seeders;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Catalog\CatalogService;
use App\Services\Orders\PosService;
use App\Services\Platform\TenantProvisioner;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * A realistic demo tenant modelled on the real laundry data: "مغاسل النقاء", with an
 * authentic Saudi laundry catalogue (shoes, menswear, womenswear, linens) at real
 * prices, real-looking customers, and a few paid orders so the board and ledger carry
 * genuine, balanced movement.
 *
 * Idempotent and self-sufficient — run with: php artisan db:seed --class=DemoSeeder
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEMO_SLUG = 'gaslah-demo';

    private const PASSWORD = '123456';

    /**
     * The express surcharge in the source data is consistently half the base price.
     */
    private const EXPRESS_RATIO = 0.5;

    /**
     * The catalogue, grouped by category. Each product lists its base price per service
     * type (wash / iron / wash_iron); the express surcharge is derived. Shoe products
     * are wash-only. Prices are the real ones (a few obvious data-entry typos fixed).
     *
     * @var array<string, array<string, array{wash?: float, iron?: float, wash_iron?: float}>>
     */
    private const CATALOG = [
        'أحذية' => [
            'حذاء رياضي' => ['wash' => 15],
            'حذاء جلد' => ['wash' => 12],
            'حذاء كلاسيك' => ['wash' => 12],
            'صندل / شبشب' => ['wash' => 8],
        ],
        'ثياب رجالية' => [
            'ثوب أبيض' => ['wash' => 7, 'iron' => 4.2, 'wash_iron' => 9.8],
            'ثوب ملون' => ['wash' => 7, 'iron' => 4.2, 'wash_iron' => 9.8],
            'قميص' => ['wash' => 6, 'iron' => 3.6, 'wash_iron' => 8.4],
            'بنطلون' => ['wash' => 6, 'iron' => 3.6, 'wash_iron' => 8.4],
            'شماغ' => ['wash' => 5, 'iron' => 3, 'wash_iron' => 7],
            'غترة' => ['wash' => 5, 'iron' => 3, 'wash_iron' => 7],
            'طاقية' => ['wash' => 3, 'iron' => 1.8, 'wash_iron' => 4.2],
            'بدلة قطعتين' => ['wash' => 18, 'iron' => 10.8, 'wash_iron' => 25.2],
            'بدلة 3 قطعة' => ['wash' => 25, 'iron' => 15, 'wash_iron' => 35],
            'جاكيت' => ['wash' => 12, 'iron' => 7.2, 'wash_iron' => 16.8],
            'معطف / بالطو' => ['wash' => 15, 'iron' => 9, 'wash_iron' => 21],
            'بيجامة' => ['wash' => 8, 'iron' => 4.8, 'wash_iron' => 11.2],
        ],
        'ثياب نسائية' => [
            'عباية' => ['wash' => 12, 'iron' => 7.2, 'wash_iron' => 16.8],
            'فستان' => ['wash' => 25, 'iron' => 15, 'wash_iron' => 35],
            'فستان حرير' => ['wash' => 35, 'iron' => 21, 'wash_iron' => 49],
            'بشت / فروة' => ['wash' => 35, 'iron' => 21, 'wash_iron' => 49],
            'تنورة' => ['wash' => 12, 'iron' => 7.2, 'wash_iron' => 16.8],
            'بلوزة حرير' => ['wash' => 12, 'iron' => 7.2, 'wash_iron' => 16.8],
            'قميص نوم' => ['wash' => 12, 'iron' => 7.2, 'wash_iron' => 16.8],
        ],
        'مفروشات وسجاد' => [
            'بطانية' => ['wash' => 25, 'wash_iron' => 56],
            'ستائر' => ['wash' => 25, 'iron' => 15, 'wash_iron' => 35],
            'سجاد' => ['wash' => 30, 'iron' => 18, 'wash_iron' => 42],
            'شرشف مفرد' => ['wash' => 10, 'iron' => 6, 'wash_iron' => 14],
            'شرشف مزدوج' => ['wash' => 15, 'iron' => 9, 'wash_iron' => 21],
            'كيس مخدة' => ['wash' => 5, 'iron' => 3, 'wash_iron' => 7],
            'منشفة / منديل' => ['wash' => 3, 'iron' => 1.8, 'wash_iron' => 4.2],
            'مفرش' => ['wash' => 30, 'iron' => 18, 'wash_iron' => 42],
        ],
    ];

    /**
     * Real customer names from the source data, with normalised Saudi phone numbers.
     *
     * @var array<int, array{name: string, phone: string, type: string}>
     */
    private const CUSTOMERS = [
        ['name' => 'عميل نقدي', 'phone' => '0500000000', 'type' => 'regular'],
        ['name' => 'محمد الغامدي', 'phone' => '0505452937', 'type' => 'vip'],
        ['name' => 'فراس', 'phone' => '0566332280', 'type' => 'regular'],
        ['name' => 'عبدالله', 'phone' => '0566338773', 'type' => 'regular'],
        ['name' => 'خالد عبدالرحمن', 'phone' => '0592377112', 'type' => 'regular'],
    ];

    public function run(): void
    {
        if (Organization::query()->where('slug', 'like', self::DEMO_SLUG.'%')->exists()) {
            $this->command?->warn('Demo tenant already exists — skipping DemoSeeder.');

            return;
        }

        // Self-sufficient: seed the entitlement feature catalogue so the demo runs alone.
        $this->call(FeatureSeeder::class);

        $this->seedPlatformOwner();

        [$organization, $mainBranch, $admin] = $this->seedTenant();
        $northBranch = $this->seedSecondBranch($organization);

        app(ChartOfAccountsService::class)->ensureChartOfAccounts($organization->getKey());

        $products = $this->seedCatalog($organization);
        $customers = $this->seedCustomers($organization, $mainBranch);
        $this->seedOrders($organization, $mainBranch, $admin, $customers, $products);

        $this->report($organization, $admin, [$mainBranch, $northBranch]);
    }

    /**
     * A Gaslah operator for the platform / admin console.
     */
    private function seedPlatformOwner(): void
    {
        User::query()->firstOrCreate(
            ['email' => 'owner@gaslah.com'],
            ['name' => 'مالك المنصة', 'password' => self::PASSWORD]
        )->forceFill([
            'is_platform_owner' => true,
            'platform_role' => PlatformRoleEnum::Owner->value,
        ])->save();
    }

    /**
     * @return array{0: Organization, 1: Branch, 2: User}
     */
    private function seedTenant(): array
    {
        $result = app(TenantProvisioner::class)->provision([
            'org_name' => 'مغاسل النقاء',
            'admin_name' => 'مدير المغسلة',
            'email' => 'admin@naqaa.com',
            'password' => self::PASSWORD,
            'phone' => '0561234567',
        ]);

        $result['organization']->forceFill([
            'slug' => self::DEMO_SLUG,
            'phone' => '0112345678',
            'address' => 'الرياض — حي النخيل',
            'vat_number' => '300012345600003',
            'cr_number' => '1010123456',
        ])->save();

        return [$result['organization'], $result['branch'], $result['user']];
    }

    private function seedSecondBranch(Organization $organization): Branch
    {
        return Branch::query()->create([
            'organization_id' => $organization->getKey(),
            'name' => 'فرع الشمال',
            'code' => 'NORTH',
            'address' => 'الرياض — حي الياسمين',
            'is_active' => true,
        ]);
    }

    /**
     * @return array<int, Product>
     */
    private function seedCatalog(Organization $organization): array
    {
        $catalog = app(CatalogService::class);
        $products = [];

        foreach (self::CATALOG as $categoryName => $items) {
            $category = $catalog->createCategory($organization->getKey(), ['name' => $categoryName]);

            foreach ($items as $name => $prices) {
                $products[] = $catalog->createProduct($organization->getKey(), [
                    'name' => $name,
                    'category_id' => $category->getKey(),
                    'cells' => $this->cellsFor($prices),
                ]);
            }
        }

        return $products;
    }

    /**
     * @param  array{wash?: float, iron?: float, wash_iron?: float}  $prices
     * @return array<string, array{base_price: float, express_surcharge: float, is_express_available: bool}>
     */
    private function cellsFor(array $prices): array
    {
        $cells = [];

        foreach ($prices as $type => $base) {
            $cells[$type] = [
                'base_price' => $base,
                'express_surcharge' => round($base * self::EXPRESS_RATIO, 2),
                'is_express_available' => true,
            ];
        }

        return $cells;
    }

    /**
     * @return array<int, Customer>
     */
    private function seedCustomers(Organization $organization, Branch $branch): array
    {
        return collect(self::CUSTOMERS)->map(fn (array $customer) => Customer::query()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'name' => $customer['name'],
            'phone' => $customer['phone'],
            'type' => $customer['type'],
        ]))->all();
    }

    /**
     * A few paid cash orders so the board and the ledger have real, balanced movement.
     *
     * @param  array<int, Customer>  $customers
     * @param  array<int, Product>  $products
     */
    private function seedOrders(Organization $organization, Branch $branch, User $admin, array $customers, array $products): void
    {
        $pos = app(PosService::class);
        $withCells = array_values(array_filter($products, fn (Product $p) => $p->services->isNotEmpty()));

        foreach ([1, 2, 3] as $i) {
            $customer = $customers[$i % count($customers)];
            $service = $withCells[($i * 3) % count($withCells)]->services->first();

            $order = $pos->create($organization->getKey(), $branch, $admin->getKey(), [
                'customer_id' => $customer->getKey(),
                'items' => [[
                    'service_id' => $service->getKey(),
                    'quantity' => $i + 1,
                    'is_express' => $i === 3,
                ]],
                'payment' => ['method' => 'cash'],
            ]);

            $pos->postAccounting($order);
        }
    }

    /**
     * @param  array<int, Branch>  $branches
     */
    private function report(Organization $organization, User $admin, array $branches): void
    {
        $this->command?->info('✔ Demo tenant ready.');
        $this->command?->table(
            ['Surface', 'Login', 'Password'],
            [
                ['Platform operator', 'owner@gaslah.com', self::PASSWORD],
                ['Tenant super-admin', $admin->email, self::PASSWORD],
            ]
        );
        $branchNames = implode('، ', array_map(fn (Branch $b) => $b->name, $branches));
        $this->command?->info("Organization: {$organization->name} (slug: {$organization->slug}) — branches: {$branchNames}");
    }
}
