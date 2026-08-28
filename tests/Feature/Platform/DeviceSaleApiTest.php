<?php

namespace Tests\Feature\Platform;

use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\Account;
use App\Models\DeviceSale;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\PlatformDevice;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\Platform\PlatformBooks;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeviceSaleApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private PlatformDevice $device;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization] = $this->createTenant();

        // 1150 inclusive → 1000 net + 150 VAT.
        $this->device = PlatformDevice::factory()->create(['name' => 'جهاز نقاط بيع', 'price' => 1150]);

        Sanctum::actingAs($this->owner());
    }

    public function test_a_draft_prices_the_lines_and_extracts_vat(): void
    {
        $this->postJson('/api/admin/device-sales', [
            'organization_id' => $this->organization->getKey(),
            'lines' => [['device_id' => $this->device->getKey(), 'qty' => 2]],
            'payment_method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', '2300.00')
            ->assertJsonPath('data.vat', '300.00')
            ->assertJsonPath('data.subtotal', '2000.00')
            ->assertJsonPath('data.buyer_name', $this->organization->name)
            ->assertJsonPath('data.items.0.qty', 2)
            ->assertJsonPath('data.icv', null);
    }

    public function test_confirming_issues_on_its_own_chain_and_credits_the_device_account(): void
    {
        $saleId = $this->draft();

        $this->postJson("/api/admin/device-sales/{$saleId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.icv', 1);

        $sale = DeviceSale::query()->find($saleId);
        $this->assertNotNull($sale->hash);
        $this->assertNotNull($sale->qr);

        // Revenue lands on the dedicated device account, not on subscription revenue.
        $booksOrgId = app(PlatformBooks::class)->storedOrgId();
        $entry = JournalEntry::query()
            ->where('organization_id', $booksOrgId)
            ->where('ref_type', 'DeviceSale')
            ->where('ref_id', (string) $saleId)
            ->first();
        $this->assertNotNull($entry);

        $deviceAccount = Account::query()
            ->where('organization_id', $booksOrgId)
            ->where('system_key', SystemAccountEnum::DeviceSales->value)
            ->first();
        $this->assertNotNull($deviceAccount);
        $this->assertSame('4120', $deviceAccount->code);

        $credited = (float) JournalLine::query()
            ->where('journal_entry_id', $entry->getKey())
            ->where('account_id', $deviceAccount->getKey())
            ->sum('credit');
        $this->assertSame(1000.0, round($credited, 2));

        $debit = (float) JournalLine::query()->where('journal_entry_id', $entry->getKey())->sum('debit');
        $credit = (float) JournalLine::query()->where('journal_entry_id', $entry->getKey())->sum('credit');
        $this->assertSame(round($debit, 2), round($credit, 2));
    }

    public function test_the_device_chain_is_independent_of_the_subscription_chain(): void
    {
        // Issue a subscription invoice first; the device series must still start at 1.
        $plan = PlatformPlan::factory()->create(['monthly_price' => 115]);
        $invoiceId = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(), 'payment_method' => 'cash',
        ])->json('data.id');
        $this->postJson("/api/admin/invoices/{$invoiceId}/confirm")->assertOk();

        $saleId = $this->draft();
        $this->postJson("/api/admin/device-sales/{$saleId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.icv', 1);
    }

    public function test_an_external_buyer_needs_a_name_and_a_tenant_does_not(): void
    {
        $this->postJson('/api/admin/device-sales', [
            'lines' => [['device_id' => $this->device->getKey(), 'qty' => 1]],
            'payment_method' => 'cash',
        ])->assertStatus(422)->assertJsonValidationErrors('buyer_name');

        $this->postJson('/api/admin/device-sales', [
            'buyer_name' => 'مؤسسة خارجية',
            'lines' => [['device_id' => $this->device->getKey(), 'qty' => 1]],
            'payment_method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.is_external_buyer', true)
            ->assertJsonPath('data.buyer_name', 'مؤسسة خارجية');
    }

    public function test_the_platform_cannot_be_invoiced_for_its_own_devices(): void
    {
        $booksOrg = app(PlatformBooks::class)->organization();

        $this->postJson('/api/admin/device-sales', [
            'organization_id' => $booksOrg->getKey(),
            'lines' => [['device_id' => $this->device->getKey(), 'qty' => 1]],
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_a_second_confirm_is_refused_and_an_issued_sale_cannot_be_deleted(): void
    {
        $saleId = $this->draft();

        $this->postJson("/api/admin/device-sales/{$saleId}/confirm")->assertOk();
        $this->postJson("/api/admin/device-sales/{$saleId}/confirm")->assertStatus(409);
        $this->deleteJson("/api/admin/device-sales/{$saleId}")->assertStatus(409);
    }

    public function test_pricing_the_catalogue_needs_more_than_reading_it(): void
    {
        Sanctum::actingAs($this->adminWith(PlatformPermissionEnum::ViewFinance));

        $this->getJson('/api/admin/devices')->assertOk();
        $this->postJson('/api/admin/devices', ['name' => 'جهاز', 'price' => 100])->assertStatus(403);
    }

    public function test_the_listing_totals_only_issued_sales(): void
    {
        $confirmed = $this->draft();
        $this->draft();
        $this->postJson("/api/admin/device-sales/{$confirmed}/confirm")->assertOk();

        $this->getJson('/api/admin/device-sales')
            ->assertOk()
            ->assertJsonPath('data.data.total', 2)
            ->assertJsonPath('data.totals.issued_count', 1)
            ->assertJsonPath('data.totals.revenue', 1000);
    }

    public function test_the_hardware_catalogue_is_not_readable_by_a_tenant(): void
    {
        // "Any admin reads it" means any *platform* admin. Prices here are the operator's
        // cost base, not something a laundry it sells to may browse.
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->createUser());

        $this->getJson('/api/admin/devices')->assertStatus(403);
        $this->getJson("/api/admin/devices/{$this->device->getKey()}")->assertStatus(403);
    }

    private function draft(): int
    {
        return $this->postJson('/api/admin/device-sales', [
            'organization_id' => $this->organization->getKey(),
            'lines' => [['device_id' => $this->device->getKey(), 'qty' => 1]],
            'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');
    }

    private function adminWith(PlatformPermissionEnum $permission): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();
        $user->platformPermissions()->create(['permission' => $permission->value]);

        return $user;
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}
