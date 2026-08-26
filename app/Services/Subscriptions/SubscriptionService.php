<?php

namespace App\Services\Subscriptions;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Enum\Subscriptions\SubscriptionStatusEnum;
use App\Enum\Subscriptions\SubscriptionTypeEnum;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\WalletTransaction;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Orders\PosOtpService;
use App\Services\Payments\WalletService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sells and collects customer subscriptions.
 *
 * A purchase and its collection are two separate acts: buying only writes the
 * subscription with its prepaid balance, while a later pay recognises the money. A
 * priced period is collected exactly once — the guard rejects a second charge and the
 * cash/bank ledger entry is idempotent on the subscription — and a wallet collection
 * burns the customer's OTP consent atomically before the balance moves.
 */
class SubscriptionService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
        private readonly WalletService $wallet,
        private readonly PosOtpService $otp,
    ) {}

    /**
     * Create a customer's subscription from a plan, seeding its period and balances.
     */
    public function purchase(SubscriptionPlan $plan, Customer $customer): Subscription
    {
        $startAt = Carbon::now();

        return Subscription::query()->create([
            'organization_id' => $plan->organization_id,
            'customer_id' => $customer->getKey(),
            'plan_id' => $plan->getKey(),
            'branch_id' => $customer->branch_id,
            'start_at' => $startAt,
            'end_at' => $startAt->copy()->addMonths($plan->cycle->months()),
            'status' => SubscriptionStatusEnum::Active->value,
            'auto_renew' => $plan->auto_renew,
            ...$this->initialBalances($plan),
        ]);
    }

    /**
     * Collect a subscription period's price and return a printable receipt.
     *
     * @return array{subscription: Subscription, receipt: array<string, mixed>, amount: float, method: string}
     */
    public function pay(Subscription $subscription, PaymentMethodEnum $method, ?string $otpToken): array
    {
        $plan = $subscription->plan;
        $amount = round((float) $plan->price, 2);

        if ($amount <= 0) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.subscription_price_zero'));
        }

        // A period is collected once. The cash/bank entry is idempotent on the
        // subscription too, but this friendly guard fails before any money moves.
        if ($this->isPaid($subscription)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.subscription_already_paid'));
        }

        $customer = $subscription->customer;

        match ($method) {
            PaymentMethodEnum::Wallet => $this->collectFromWallet($subscription, $customer, $amount, $otpToken),
            default => $this->postCashCollection($subscription, $method, $amount),
        };

        return [
            'subscription' => $subscription,
            'amount' => $amount,
            'method' => $method->value,
            'receipt' => $this->receipt($subscription, $amount, $method),
        ];
    }

    /**
     * Whether the subscription's period has already been collected — by a cash/bank
     * entry or by a wallet debit against the subscription.
     */
    public function isPaid(Subscription $subscription): bool
    {
        $hasEntry = JournalEntry::query()
            ->where('organization_id', $subscription->organization_id)
            ->where('source', JournalSourceEnum::Payment->value)
            ->where('ref_type', 'Subscription')
            ->where('ref_id', (string) $subscription->getKey())
            ->exists();

        if ($hasEntry) {
            return true;
        }

        return WalletTransaction::query()
            ->where('customer_id', $subscription->customer_id)
            ->where('type', WalletTransactionTypeEnum::Debit->value)
            ->where('ref_id', $subscription->getKey())
            ->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{remaining_quota: float|null, remaining_balance: float|null}
     */
    private function initialBalances(SubscriptionPlan $plan): array
    {
        $quota = $plan->quota === null ? null : round((float) $plan->quota, 2);

        return match ($plan->type) {
            SubscriptionTypeEnum::PieceQuota => ['remaining_quota' => $quota, 'remaining_balance' => null],
            SubscriptionTypeEnum::PrepaidBalance => ['remaining_quota' => null, 'remaining_balance' => $quota],
            SubscriptionTypeEnum::UnlimitedService => ['remaining_quota' => null, 'remaining_balance' => null],
        };
    }

    /**
     * Cash/card/transfer collection: the customer paid in advance, so the money is
     * booked as deferred revenue until the service is delivered. Idempotent on the
     * subscription id, so a repeated call never collects twice.
     */
    private function postCashCollection(Subscription $subscription, PaymentMethodEnum $method, float $amount): void
    {
        $organizationId = $subscription->organization_id;
        $fundingAccount = $method === PaymentMethodEnum::Cash ? SystemAccountEnum::Cash : SystemAccountEnum::Bank;

        $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::Payment,
            'ref_type' => 'Subscription',
            'ref_id' => $subscription->getKey(),
            'memo' => __('api.subscription_payment_memo', ['name' => $subscription->plan->name]),
            'branch_id' => $subscription->branch_id,
            'lines' => [
                ['account_id' => $this->chart->systemAccount($organizationId, $fundingAccount)->getKey(), 'debit' => $amount],
                ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::DeferredRevenue)->getKey(), 'credit' => $amount],
            ],
        ]);
    }

    /**
     * Wallet collection draws stored value, so it demands the same OTP consent the POS
     * wallet path does: the proof is burned atomically before the debit, so a replayed
     * proof can never spend the balance twice. No ledger entry — the value was already
     * booked as deferred revenue when the wallet was topped up.
     */
    private function collectFromWallet(Subscription $subscription, Customer $customer, float $amount, ?string $otpToken): void
    {
        if (! $this->otp->reserve((string) $otpToken, $customer)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.otp_consent_required'));
        }

        $this->wallet->debit(
            $customer,
            $amount,
            __('api.subscription_payment_memo', ['name' => $subscription->plan->name]),
            $subscription->getKey(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function receipt(Subscription $subscription, float $amount, PaymentMethodEnum $method): array
    {
        $customer = $subscription->customer;
        $plan = $subscription->plan;

        return [
            'receipt_no' => 'SUB-'.str_pad((string) $subscription->getKey(), 8, '0', STR_PAD_LEFT),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'plan_name' => $plan->name,
            'cycle' => $plan->cycle->value,
            'amount' => $amount,
            'method' => $method->value,
            'start_at' => $subscription->start_at,
            'end_at' => $subscription->end_at,
            'created_at' => Carbon::now(),
        ];
    }
}
