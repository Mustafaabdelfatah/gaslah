<?php

namespace Tests\Feature\Orders;

use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Orders\OrderStatusService;
use App\Services\Orders\PosOtpService;
use App\Services\Orders\PosService;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosServiceTest extends TestCase
{
    use RefreshDatabase;

    private PosService $pos;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pos = app(PosService::class);
        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());

        $this->customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        $category = ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
        $product = Product::factory()->create(['organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey()]);
        $this->service = Service::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'product_id' => $product->getKey(),
            'base_price' => 100,
            'express_surcharge' => 20,
            'is_express_available' => true,
        ]);
    }

    public function test_line_prices_are_taken_from_the_catalog_not_the_client(): void
    {
        // The client sends a price of 1; it is ignored and the catalog's 100 is used.
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 2, 'unit_price' => 1]],
        ]);

        $this->assertEquals('100.00', $order->items->first()->unit_price);
        $this->assertEquals('200.00', $order->subtotal);
        $this->assertEquals('230.00', $order->grand_total); // 200 + 15% tax
    }

    public function test_express_surcharge_is_applied_only_when_available(): void
    {
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1, 'is_express' => true]],
        ]);

        // 100 base + 20 express surcharge on the unit price.
        $this->assertEquals('120.00', $order->items->first()->unit_price);
        $this->assertTrue($order->items->first()->is_express);
    }

    public function test_a_repeated_client_request_id_returns_the_same_order(): void
    {
        $payload = [
            'customer_id' => $this->customer->getKey(),
            'client_request_id' => 'cart-xyz',
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
        ];

        $first = $this->pos->create($this->organization->getKey(), $this->branch, null, $payload);
        $second = $this->pos->create($this->organization->getKey(), $this->branch, null, $payload);

        // Idempotent: no double billing on an offline re-sync.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_cash_payment_marks_the_order_paid(): void
    {
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'cash'],
        ]);

        $this->assertSame(PaymentStatusEnum::Paid, $order->payment_status);
        $this->assertEquals('115.00', $order->paid_total);
        $this->assertSame(1, $order->payments->count());
    }

    public function test_cash_overpay_to_wallet_tops_up_the_balance(): void
    {
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount' => 200, 'overpay_to' => 'wallet'],
        ]);

        // Grand total 115; 85 change parked in the wallet.
        $this->assertSame(PaymentStatusEnum::Paid, $order->payment_status);
        $this->assertEquals('85.00', $this->customer->fresh()->wallet_balance);
        $this->assertNull($order->payments()->first()->cash_tendered);
    }

    public function test_cash_returned_as_change_is_kept_for_the_receipt_without_inflating_collection(): void
    {
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'cash', 'amount' => 200, 'overpay_to' => 'change'],
        ]);

        $payment = $order->payments()->firstOrFail();

        $this->assertEquals('115.00', $payment->amount);
        $this->assertEquals('200.00', $payment->cash_tendered);
        $this->assertEquals('115.00', $order->paid_total);
    }

    public function test_a_card_payment_without_confirmation_is_refused(): void
    {
        $this->assertAborts(422, fn () => $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'card'],
        ]));
    }

    public function test_a_terminal_card_payment_needs_a_network_reference(): void
    {
        $this->assertAborts(422, fn () => $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'card', 'verify_mode' => 'terminal'],
        ]));
    }

    public function test_a_wallet_payment_burns_the_consent_proof_before_debiting(): void
    {
        app(WalletService::class)->credit($this->customer, 500, WalletTransactionTypeEnum::Topup, 'Top-up');
        $proof = app(PosOtpService::class);
        // Mint a proof directly (request+verify path is covered in the OTP test).
        $token = $this->issueProof();

        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'wallet', 'otp_token' => $token],
        ]);

        $this->assertSame(PaymentStatusEnum::Paid, $order->payment_status);
        $this->assertEquals('385.00', $this->customer->fresh()->wallet_balance); // 500 - 115

        // The proof is single-use: it cannot pay a second order.
        $this->assertFalse($proof->reserve($token, $this->customer->fresh()));
    }

    public function test_a_wallet_payment_without_a_valid_proof_is_refused(): void
    {
        app(WalletService::class)->credit($this->customer, 500, WalletTransactionTypeEnum::Topup, 'Top-up');

        $this->assertAborts(422, fn () => $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'wallet', 'otp_token' => 'forged-token'],
        ]));
    }

    public function test_a_foreign_service_fails_the_whole_cart(): void
    {
        $foreign = Service::factory()->create();

        $this->assertAborts(404, fn () => $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $foreign->getKey(), 'quantity' => 1]],
        ]));
    }

    public function test_the_sale_posts_a_balanced_ledger_entry(): void
    {
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'cash'],
        ]);
        $this->pos->postAccounting($order);

        // Receivable debited for the gross of the sale.
        $sale = JournalEntry::query()->where('ref_type', 'Order')->where('ref_id', $order->getKey())->with('lines')->first();
        $this->assertEquals('115.00', $this->line($sale, SystemAccountEnum::AccountsReceivable)->debit);
        $this->assertTrue(app(AccountingReportService::class)->trialBalance($this->organization->getKey())['balanced']);
    }

    public function test_cancelling_a_wallet_order_reverses_the_sale_and_refunds_the_wallet(): void
    {
        app(WalletService::class)->credit($this->customer, 500, WalletTransactionTypeEnum::Topup, 'Top-up');
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'wallet', 'otp_token' => $this->issueProof()],
        ]);
        $this->pos->postAccounting($order);

        app(OrderStatusService::class)->transition($order->fresh(), OrderStatusEnum::Cancelled);

        // Wallet is made whole and the books still balance.
        $this->assertEquals('500.00', $this->customer->fresh()->wallet_balance);
        $this->assertTrue(app(AccountingReportService::class)->trialBalance($this->organization->getKey())['balanced']);
    }

    public function test_the_status_machine_refuses_an_illegal_transition(): void
    {
        $order = $this->pos->create($this->organization->getKey(), $this->branch, null, [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
        ]);

        // Received cannot jump straight to Delivered.
        $this->assertAborts(422, fn () => app(OrderStatusService::class)->transition($order, OrderStatusEnum::Delivered));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function issueProof(): string
    {
        $result = app(PosOtpService::class)->request($this->customer);

        return app(PosOtpService::class)->verify($this->customer, $result['dev_code'])['proof_token'];
    }

    private function line(JournalEntry $entry, SystemAccountEnum $key): JournalLine
    {
        $accountId = Account::query()->forOrganization($this->organization->getKey())->systemKey($key)->value('id');

        return $entry->lines->firstWhere('account_id', $accountId);
    }
}
