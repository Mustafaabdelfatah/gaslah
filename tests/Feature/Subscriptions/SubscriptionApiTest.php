<?php

namespace Tests\Feature\Subscriptions;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Enum\Subscriptions\SubscriptionStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Payments\WalletService;
use App\Services\Subscriptions\SubscriptionService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());

        $this->customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */
    public function test_a_manager_creates_a_plan_and_the_listing_is_cheapest_first(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/subscription-plans', [
            'name' => 'Premium', 'cycle' => 'monthly', 'type' => 'piece_quota', 'price' => 200, 'quota' => 40,
        ])->assertCreated();
        $this->postJson('/api/subscription-plans', [
            'name' => 'Basic', 'cycle' => 'monthly', 'type' => 'piece_quota', 'price' => 90, 'quota' => 20,
        ])->assertCreated();

        $response = $this->getJson('/api/subscription-plans')->assertOk();
        $this->assertSame('Basic', $response->json('data.data.0.name'));
    }

    public function test_a_cashier_cannot_create_a_plan(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->postJson('/api/subscription-plans', [
            'name' => 'X', 'cycle' => 'monthly', 'type' => 'piece_quota', 'price' => 10,
        ])->assertStatus(403);
    }

    public function test_operations_are_refused_when_the_feature_is_disabled(): void
    {
        $this->organization->update(['feature_overrides' => ['subscriptions' => false]]);
        $this->actingAsManager();

        $this->getJson('/api/subscription-plans')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Purchase & balances
    |--------------------------------------------------------------------------
    */
    public function test_buying_a_piece_quota_plan_seeds_the_quota(): void
    {
        $this->actingAsManager();
        $plan = SubscriptionPlan::factory()->pieceQuota(30)->create(['organization_id' => $this->organization->getKey()]);

        $response = $this->postJson('/api/subscriptions', [
            'customer_id' => $this->customer->getKey(), 'plan_id' => $plan->getKey(),
        ])->assertCreated();

        $subscription = Subscription::query()->find($response->json('data.id'));
        $this->assertEquals('30.00', $subscription->remaining_quota);
        $this->assertNull($subscription->remaining_balance);
        $this->assertSame(SubscriptionStatusEnum::Active, $subscription->status);
    }

    public function test_buying_a_prepaid_plan_seeds_the_balance(): void
    {
        $this->actingAsManager();
        $plan = SubscriptionPlan::factory()->prepaidBalance(500)->create(['organization_id' => $this->organization->getKey()]);

        $response = $this->postJson('/api/subscriptions', [
            'customer_id' => $this->customer->getKey(), 'plan_id' => $plan->getKey(),
        ])->assertCreated();

        $subscription = Subscription::query()->find($response->json('data.id'));
        $this->assertEquals('500.00', $subscription->remaining_balance);
        $this->assertNull($subscription->remaining_quota);
    }

    public function test_a_foreign_plan_cannot_be_bought(): void
    {
        $this->actingAsManager();
        $foreign = SubscriptionPlan::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->postJson('/api/subscriptions', [
            'customer_id' => $this->customer->getKey(), 'plan_id' => $foreign->getKey(),
        ])->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Collection (pay)
    |--------------------------------------------------------------------------
    */
    public function test_paying_a_plan_in_cash_books_deferred_revenue_and_blocks_a_second_charge(): void
    {
        $this->actingAsManager();
        $subscription = $this->buy(SubscriptionPlan::factory()->pieceQuota(30)->create([
            'organization_id' => $this->organization->getKey(), 'price' => 150,
        ]));

        $this->postJson("/api/subscriptions/{$subscription->getKey()}/pay", ['method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.receipt.amount', 150);

        $entry = JournalEntry::query()
            ->where('source', JournalSourceEnum::Payment->value)
            ->where('ref_type', 'Subscription')
            ->where('ref_id', (string) $subscription->getKey())
            ->first();
        $this->assertNotNull($entry);

        // A period is collected only once.
        $this->postJson("/api/subscriptions/{$subscription->getKey()}/pay", ['method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_a_free_plan_has_nothing_to_collect(): void
    {
        $this->actingAsManager();
        $subscription = $this->buy(SubscriptionPlan::factory()->free()->create(['organization_id' => $this->organization->getKey()]));

        $this->postJson("/api/subscriptions/{$subscription->getKey()}/pay", ['method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_paying_from_the_wallet_requires_otp_consent_then_debits(): void
    {
        $cashier = $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        app(WalletService::class)->credit($this->customer, 300, WalletTransactionTypeEnum::Topup, 'Top-up');

        $subscription = $this->buy(SubscriptionPlan::factory()->pieceQuota(30)->create([
            'organization_id' => $this->organization->getKey(), 'price' => 120,
        ]));

        // Without a valid consent proof the wallet is untouched.
        $this->postJson("/api/subscriptions/{$subscription->getKey()}/pay", ['method' => 'wallet', 'otp_token' => 'nope'])
            ->assertStatus(422);
        $this->assertEquals('300.00', $this->customer->fresh()->wallet_balance);

        $code = $this->postJson('/api/pos/otp/request', ['customer_id' => $this->customer->getKey()])->json('data.dev_code');
        $proof = $this->postJson('/api/pos/otp/verify', ['customer_id' => $this->customer->getKey(), 'code' => $code])->json('data.proof_token');

        $this->postJson("/api/subscriptions/{$subscription->getKey()}/pay", ['method' => 'wallet', 'otp_token' => $proof])
            ->assertOk();
        $this->assertEquals('180.00', $this->customer->fresh()->wallet_balance);
    }

    /*
    |--------------------------------------------------------------------------
    | POS consumption
    |--------------------------------------------------------------------------
    */
    public function test_a_prepaid_subscription_settles_a_pos_order(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        $service = $this->service();

        // A free prepaid plan needs no collection, so consumption can be exercised directly.
        $plan = SubscriptionPlan::factory()->prepaidBalance(500)->free()->create(['organization_id' => $this->organization->getKey()]);
        $subscription = $this->buy($plan);

        $response = $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'subscription'],
        ])->assertCreated()->assertJsonPath('data.payment_status', 'paid');

        // Order 1 x 100 + 15% tax = 115 drawn from the 500 balance.
        $this->assertEquals('385.00', $subscription->fresh()->remaining_balance);
        $this->assertSame($subscription->getKey(), (int) $response->json('data.subscription_id'));

        $entry = JournalEntry::query()
            ->where('source', JournalSourceEnum::Payment->value)
            ->where('ref_type', 'SubscriptionConsume')
            ->where('ref_id', (string) $response->json('data.id'))
            ->first();
        $this->assertNotNull($entry);
    }

    public function test_a_piece_quota_subscription_draws_pieces(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        $service = $this->service();

        $plan = SubscriptionPlan::factory()->pieceQuota(5)->free()->create(['organization_id' => $this->organization->getKey()]);
        $subscription = $this->buy($plan);

        $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $service->getKey(), 'quantity' => 2]],
            'payment' => ['method' => 'subscription'],
        ])->assertCreated();

        $this->assertEquals('3.00', $subscription->fresh()->remaining_quota);
    }

    public function test_consumption_is_refused_when_quota_is_insufficient(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        $service = $this->service();

        $plan = SubscriptionPlan::factory()->pieceQuota(1)->free()->create(['organization_id' => $this->organization->getKey()]);
        $this->buy($plan);

        $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $service->getKey(), 'quantity' => 3]],
            'payment' => ['method' => 'subscription'],
        ])->assertStatus(422);
    }

    public function test_consumption_is_refused_when_a_priced_subscription_is_unpaid(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        $service = $this->service();

        // Priced but never collected.
        $plan = SubscriptionPlan::factory()->prepaidBalance(500)->create([
            'organization_id' => $this->organization->getKey(), 'price' => 150,
        ]);
        $this->buy($plan);

        $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'subscription'],
        ])->assertStatus(422);
    }

    public function test_consumption_is_refused_without_any_active_subscription(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        $service = $this->service();

        $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'subscription'],
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function actingAsManager(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    private function buy(SubscriptionPlan $plan): Subscription
    {
        return app(SubscriptionService::class)->purchase($plan, $this->customer);
    }

    private function service(): Service
    {
        $category = ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
        $product = Product::factory()->create(['organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey()]);

        return Service::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'product_id' => $product->getKey(),
            'base_price' => 100,
        ]);
    }
}
