<?php

namespace Tests\Feature\Loyalty;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Loyalty\LoyaltyTransactionTypeEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Models\Organization;
use App\Services\Accounting\ChartOfAccountsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoyaltyApiTest extends TestCase
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
    | Programme
    |--------------------------------------------------------------------------
    */
    public function test_the_programme_returns_a_defaults_template_when_none_is_saved(): void
    {
        $this->actingAsManager();

        $this->getJson('/api/loyalty/program')
            ->assertOk()
            ->assertJsonPath('data.exists', false)
            ->assertJsonPath('data.earn_rate', '1.00');
    }

    public function test_a_manager_saves_the_programme(): void
    {
        $this->actingAsManager();

        $this->putJson('/api/loyalty/program', [
            'name' => 'Rewards', 'earn_rate' => 2, 'point_value' => 0.25, 'expiry_months' => 6,
        ])->assertOk();

        $this->getJson('/api/loyalty/program')->assertJsonPath('data.exists', true)->assertJsonPath('data.name', 'Rewards');
    }

    public function test_a_cashier_cannot_save_the_programme(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->putJson('/api/loyalty/program', ['name' => 'X', 'earn_rate' => 1, 'point_value' => 0.1])
            ->assertStatus(403);
    }

    public function test_loyalty_is_refused_when_the_feature_is_disabled(): void
    {
        $this->organization->update(['feature_overrides' => ['loyalty' => false]]);
        $this->actingAsManager();

        $this->getJson('/api/loyalty/program')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Manual adjustment
    |--------------------------------------------------------------------------
    */
    public function test_a_positive_adjustment_earns_bonus_points(): void
    {
        $this->actingAsManager();
        $this->program();

        $this->postJson("/api/loyalty/accounts/{$this->customer->getKey()}/adjust", ['points' => 20])
            ->assertOk()
            ->assertJsonPath('data.points_balance', '20.00')
            ->assertJsonPath('data.lifetime_points', '20.00');

        $account = LoyaltyAccount::query()->where('customer_id', $this->customer->getKey())->first();
        $this->assertSame(LoyaltyTransactionTypeEnum::Bonus, $account->transactions()->first()->type);
    }

    public function test_a_negative_adjustment_draws_points_but_cannot_go_below_zero(): void
    {
        $this->actingAsManager();
        $this->program();

        $this->postJson("/api/loyalty/accounts/{$this->customer->getKey()}/adjust", ['points' => 30])->assertOk();
        $this->postJson("/api/loyalty/accounts/{$this->customer->getKey()}/adjust", ['points' => -10])
            ->assertOk()
            ->assertJsonPath('data.points_balance', '20.00')
            // Lifetime never falls on a draw-down.
            ->assertJsonPath('data.lifetime_points', '30.00');

        $this->postJson("/api/loyalty/accounts/{$this->customer->getKey()}/adjust", ['points' => -100])
            ->assertStatus(422);
    }

    public function test_adjustment_requires_a_saved_programme(): void
    {
        $this->actingAsManager();

        $this->postJson("/api/loyalty/accounts/{$this->customer->getKey()}/adjust", ['points' => 10])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Redemption
    |--------------------------------------------------------------------------
    */
    public function test_redeeming_points_credits_the_wallet_and_books_a_discount(): void
    {
        $this->actingAsManager();
        $this->program(pointValue: 0.1);
        LoyaltyAccount::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'program_id' => LoyaltyProgram::query()->first()->getKey(),
            'points_balance' => 100,
            'lifetime_points' => 100,
        ]);

        $this->postJson("/api/customers/{$this->customer->getKey()}/loyalty/redeem", ['points' => 50])
            ->assertOk()
            ->assertJsonPath('data.wallet_credit', 5)
            ->assertJsonPath('data.points_balance', 50);

        $this->assertEquals('5.00', $this->customer->fresh()->wallet_balance);

        $transaction = LoyaltyTransaction::query()->where('type', LoyaltyTransactionTypeEnum::Redeem->value)->first();
        $entry = JournalEntry::query()
            ->where('source', JournalSourceEnum::Manual->value)
            ->where('ref_type', 'LoyaltyRedemption')
            ->where('ref_id', (string) $transaction->getKey())
            ->first();
        $this->assertNotNull($entry);
    }

    public function test_redeeming_more_than_the_balance_is_refused(): void
    {
        $this->actingAsManager();
        $this->program(pointValue: 0.1);
        LoyaltyAccount::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'program_id' => LoyaltyProgram::query()->first()->getKey(),
            'points_balance' => 10,
        ]);

        $this->postJson("/api/customers/{$this->customer->getKey()}/loyalty/redeem", ['points' => 50])
            ->assertStatus(422);
    }

    public function test_redeeming_requires_a_positive_point_value(): void
    {
        $this->actingAsManager();
        $this->program(pointValue: 0);
        LoyaltyAccount::factory()->create([
            'customer_id' => $this->customer->getKey(),
            'program_id' => LoyaltyProgram::query()->first()->getKey(),
            'points_balance' => 100,
        ]);

        $this->postJson("/api/customers/{$this->customer->getKey()}/loyalty/redeem", ['points' => 50])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Listing & isolation
    |--------------------------------------------------------------------------
    */
    public function test_accounts_are_listed_richest_first(): void
    {
        $this->actingAsManager();
        $program = $this->program();

        $poor = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);
        LoyaltyAccount::factory()->create(['customer_id' => $this->customer->getKey(), 'program_id' => $program->getKey(), 'points_balance' => 500]);
        LoyaltyAccount::factory()->create(['customer_id' => $poor->getKey(), 'program_id' => $program->getKey(), 'points_balance' => 50]);

        $response = $this->getJson('/api/loyalty/accounts')->assertOk();
        $this->assertEquals('500.00', $response->json('data.0.points_balance'));
    }

    public function test_a_foreign_customer_cannot_be_adjusted(): void
    {
        $this->actingAsManager();
        $this->program();
        $foreign = Customer::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->postJson("/api/loyalty/accounts/{$foreign->getKey()}/adjust", ['points' => 10])
            ->assertStatus(404);
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

    private function program(float $pointValue = 0.1): LoyaltyProgram
    {
        return LoyaltyProgram::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'point_value' => $pointValue,
        ]);
    }
}
