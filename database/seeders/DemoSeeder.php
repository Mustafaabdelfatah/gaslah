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
 * A clean, self-contained demo tenant to click through: a platform operator, one
 * laundry organization with its main branch and a super-admin, a small catalogue,
 * a handful of customers, and a couple of paid orders so the ledger has movement.
 *
 * Idempotent: it does nothing if the demo organization already exists.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    private const DEMO_SLUG_PREFIX = 'gaslah-demo';

    private const PASSWORD = '123456';

    /**
     * @var array<int, array{name: string, name_en: string, products: array<int, array{name: string, wash_iron: float, iron: float, wash: float, express: float}>}>
     */
    private const CATALOG = [
        [
            'name' => 'ملابس رجالي', 'name_en' => 'Menswear',
            'products' => [
                ['name' => 'ثوب', 'wash_iron' => 12, 'iron' => 7, 'wash' => 8, 'express' => 6],
                ['name' => 'قميص', 'wash_iron' => 8, 'iron' => 5, 'wash' => 6, 'express' => 4],
                ['name' => 'بنطلون', 'wash_iron' => 10, 'iron' => 6, 'wash' => 7, 'express' => 5],
            ],
        ],
        [
            'name' => 'ملابس نسائي', 'name_en' => 'Womenswear',
            'products' => [
                ['name' => 'عباية', 'wash_iron' => 15, 'iron' => 9, 'wash' => 10, 'express' => 7],
                ['name' => 'فستان', 'wash_iron' => 18, 'iron' => 11, 'wash' => 12, 'express' => 8],
            ],
        ],
        [
            'name' => 'مفروشات', 'name_en' => 'Household',
            'products' => [
                ['name' => 'بطانية', 'wash_iron' => 25, 'iron' => 0, 'wash' => 20, 'express' => 10],
                ['name' => 'مفرش سرير', 'wash_iron' => 20, 'iron' => 12, 'wash' => 15, 'express' => 8],
            ],
        ],
    ];

    /**
     * @var array<int, array{name: string, phone: string, type: string}>
     */
    private const CUSTOMERS = [
        ['name' => 'أحمد المهدي', 'phone' => '0501112201', 'type' => 'vip'],
        ['name' => 'سارة العتيبي', 'phone' => '0501112202', 'type' => 'regular'],
        ['name' => 'شركة الأفق للمقاولات', 'phone' => '0501112203', 'type' => 'corporate'],
        ['name' => 'خالد الزهراني', 'phone' => '0501112204', 'type' => 'regular'],
    ];

    public function run(): void
    {
        if (Organization::query()->where('slug', 'like', self::DEMO_SLUG_PREFIX.'%')->exists()) {
            $this->command?->warn('Demo tenant already exists — skipping DemoSeeder.');

            return;
        }

        // Self-sufficient: the entitlement feature catalogue is seeded idempotently so
        // the demo runs standalone (php artisan db:seed --class=DemoSeeder).
        $this->call(FeatureSeeder::class);

        $this->seedPlatformOwner();

        [$organization, $branch, $admin] = $this->seedTenant();

        app(ChartOfAccountsService::class)->ensureChartOfAccounts($organization->getKey());

        $products = $this->seedCatalog($organization);
        $customers = $this->seedCustomers($organization, $branch);
        $this->seedOrders($organization, $branch, $admin, $customers, $products);

        $this->report($organization, $admin);
    }

    /**
     * A Gaslah operator for the platform / admin console.
     */
    private function seedPlatformOwner(): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@gaslah.com'],
            ['name' => 'مالك المنصة', 'password' => self::PASSWORD]
        );

        $owner->forceFill([
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
            'org_name' => 'مغسلة النقاء',
            'admin_name' => 'مدير المغسلة',
            'email' => 'admin@naqaa.com',
            'password' => self::PASSWORD,
            'phone' => '0509990001',
        ]);

        // Give the demo tenant a stable, recognisable slug.
        $result['organization']->forceFill(['slug' => self::DEMO_SLUG_PREFIX])->save();

        return [$result['organization'], $result['branch'], $result['user']];
    }

    /**
     * @return array<int, Product>
     */
    private function seedCatalog(Organization $organization): array
    {
        $catalog = app(CatalogService::class);
        $products = [];

        foreach (self::CATALOG as $group) {
            $category = $catalog->createCategory($organization->getKey(), [
                'name' => $group['name'],
                'name_en' => $group['name_en'],
            ]);

            foreach ($group['products'] as $product) {
                $products[] = $catalog->createProduct($organization->getKey(), [
                    'name' => $product['name'],
                    'category_id' => $category->getKey(),
                    'cells' => $this->cellsFor($product),
                ]);
            }
        }

        return $products;
    }

    /**
     * @param  array{wash_iron: float, iron: float, wash: float, express: float}  $product
     * @return array<string, array{base_price: float, express_surcharge: float, is_express_available: bool}>
     */
    private function cellsFor(array $product): array
    {
        $cells = [];

        foreach (['wash_iron', 'iron', 'wash'] as $type) {
            if ($product[$type] <= 0) {
                continue;
            }

            $cells[$type] = [
                'base_price' => $product[$type],
                'express_surcharge' => $product['express'],
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
     * A few paid cash orders so the board and the ledger have real movement.
     *
     * @param  array<int, Customer>  $customers
     * @param  array<int, Product>  $products
     */
    private function seedOrders(Organization $organization, Branch $branch, User $admin, array $customers, array $products): void
    {
        $pos = app(PosService::class);

        foreach ([0, 1, 2] as $index) {
            $customer = $customers[$index % count($customers)];
            $service = $products[$index % count($products)]->services->first();

            $order = $pos->create($organization->getKey(), $branch, $admin->getKey(), [
                'customer_id' => $customer->getKey(),
                'items' => [[
                    'service_id' => $service->getKey(),
                    'quantity' => $index + 2,
                    'is_express' => $index === 2,
                ]],
                'payment' => ['method' => 'cash'],
            ]);

            $pos->postAccounting($order);
        }
    }

    private function report(Organization $organization, User $admin): void
    {
        $this->command?->info('✔ Demo tenant ready.');
        $this->command?->table(
            ['Surface', 'Login', 'Password'],
            [
                ['Platform operator', 'owner@gaslah.com', self::PASSWORD],
                ['Tenant super-admin', $admin->email, self::PASSWORD],
            ]
        );
        $this->command?->info("Organization: {$organization->name} (slug: {$organization->slug})");
    }
}
