<?php

namespace Tests\Feature\Reports;

use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShiftApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);
    }

    public function test_opening_a_shift_and_a_second_open_is_refused(): void
    {
        $this->actingAsCashier();

        $this->postJson('/api/shifts/open', ['opening_cash' => 50])
            ->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.opening_float', 50);

        // One open shift per user.
        $this->postJson('/api/shifts/open', ['opening_cash' => 20])->assertStatus(422);
    }

    public function test_the_summary_includes_cash_payments_and_cash_top_ups(): void
    {
        $cashier = $this->actingAsCashier();
        $this->postJson('/api/shifts/open', ['opening_cash' => 50])->assertCreated();

        // A cash payment on the branch.
        $order = Order::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'customer_id' => $this->customer->getKey()]);
        $order->payments()->create(['method' => PaymentMethodEnum::Cash->value, 'amount' => 100]);

        // A cash wallet top-up (posts to CASH, no payment row).
        app(WalletService::class)->credit($this->customer, 200, WalletTransactionTypeEnum::Topup, 'Top-up');

        $this->getJson('/api/shifts/current')
            ->assertOk()
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.shift.cash_total', 100)
            ->assertJsonPath('data.shift.cash_top_ups', 200)
            // 50 float + 100 cash + 200 top-up.
            ->assertJsonPath('data.shift.expected_cash', 350);
    }

    public function test_closing_computes_the_variance(): void
    {
        $this->actingAsCashier();
        $this->postJson('/api/shifts/open', ['opening_cash' => 100])->assertCreated();

        $this->postJson('/api/shifts/close', ['actual_cash' => 90])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            // 90 actual − 100 expected.
            ->assertJsonPath('data.variance', -10);

        // No open shift remains.
        $this->getJson('/api/shifts/current')->assertOk()->assertJsonPath('data.open', false);
    }

    public function test_the_closing_note_is_kept_with_the_shift(): void
    {
        $this->actingAsCashier();
        $this->postJson('/api/shifts/open', ['opening_cash' => 50])->assertCreated();

        $this->postJson('/api/shifts/close', ['actual_cash' => 45, 'note' => 'عجز في الدرج'])
            ->assertOk()
            ->assertJsonPath('data.note', 'عجز في الدرج');

        $this->getJson('/api/shifts')->assertOk()->assertJsonPath('data.data.0.note', 'عجز في الدرج');
    }

    public function test_closing_without_an_open_shift_is_refused(): void
    {
        $this->actingAsCashier();

        $this->postJson('/api/shifts/close', ['actual_cash' => 10])->assertStatus(422);
    }

    public function test_a_reception_member_cannot_manage_shifts(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Reception));

        $this->getJson('/api/shifts/current')->assertStatus(403);
    }

    private function actingAsCashier(): User
    {
        return $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
    }
}
