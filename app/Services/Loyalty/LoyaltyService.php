<?php

namespace App\Services\Loyalty;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Loyalty\LoyaltyTransactionTypeEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use App\Models\LoyaltyTransaction;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Payments\WalletService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The loyalty programme: its configuration, manual point adjustments, and redemption
 * of points to wallet value.
 *
 * A redemption is the money-touching path: it reads the points balance under a row lock
 * and rechecks sufficiency inside the transaction so two concurrent redemptions can
 * never spend the same points twice, credits the wallet, and books the cost as a sales
 * discount against a deferred-revenue liability (idempotent on the points transaction).
 */
class LoyaltyService
{
    /**
     * Defaults shown for the settings form when no programme is saved yet.
     */
    private const DEFAULTS = [
        'name' => 'برنامج الولاء',
        'earn_rate' => 1,
        'point_value' => 0.1,
        'expiry_months' => 12,
        'is_active' => true,
    ];

    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
        private readonly WalletService $wallet,
    ) {}

    /**
     * The organization's saved programme, or an unsaved defaults template.
     */
    public function resolveProgram(int $organizationId): LoyaltyProgram
    {
        $program = LoyaltyProgram::query()->forOrganization($organizationId)->first();

        if ($program !== null) {
            return $program;
        }

        return new LoyaltyProgram([...self::DEFAULTS, 'organization_id' => $organizationId]);
    }

    /**
     * Create or update the organization's single programme.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveProgram(int $organizationId, array $data): LoyaltyProgram
    {
        return LoyaltyProgram::query()->updateOrCreate(
            ['organization_id' => $organizationId],
            $data,
        );
    }

    /**
     * Manually move a customer's points balance by a signed amount.
     *
     * The only path that earns points today: a positive delta writes Bonus, a negative
     * one Redeem. The balance never drops below zero, and lifetime points only rise.
     */
    public function adjust(Customer $customer, LoyaltyProgram $program, float $delta, ?string $note): LoyaltyAccount
    {
        return DB::transaction(function () use ($customer, $program, $delta, $note) {
            $account = $this->accountFor($customer, $program);

            $newBalance = round((float) $account->points_balance + $delta, 2);

            if ($newBalance < 0) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.loyalty_insufficient_points'));
            }

            $this->writeTransaction(
                $account,
                $delta > 0 ? LoyaltyTransactionTypeEnum::Bonus : LoyaltyTransactionTypeEnum::Redeem,
                round($delta, 2),
                $note ?? __('api.loyalty_manual_adjustment'),
            );

            $account->points_balance = $newBalance;

            if ($delta > 0) {
                $account->lifetime_points = round((float) $account->lifetime_points + $delta, 2);
            }

            $account->save();

            return $account;
        });
    }

    /**
     * Redeem points for wallet value.
     *
     * @return array{points: float, wallet_credit: float, points_balance: float, wallet_balance: float}
     */
    public function redeem(Customer $customer, LoyaltyProgram $program, float $points): array
    {
        $pointValue = round((float) $program->point_value, 4);

        if ($pointValue <= 0) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.loyalty_point_value_missing'));
        }

        // A cheap friendly precheck; the decisive one runs under the lock below.
        $account = $this->accountFor($customer, $program);
        if ((float) $account->points_balance < $points) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.loyalty_insufficient_points'));
        }

        $credit = round($points * $pointValue, 2);
        if ($credit <= 0) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.loyalty_redeem_value_zero'));
        }

        $account = DB::transaction(function () use ($account, $customer, $points, $credit) {
            $locked = LoyaltyAccount::query()->whereKey($account->getKey())->lockForUpdate()->first();

            if ((float) $locked->points_balance < $points) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.loyalty_insufficient_points'));
            }

            $transaction = $this->writeTransaction(
                $locked,
                LoyaltyTransactionTypeEnum::Redeem,
                round(-$points, 2),
                __('api.loyalty_redeem_note'),
            );

            $locked->points_balance = round((float) $locked->points_balance - $points, 2);
            $locked->save();

            // The wallet posts no ledger entry of its own here — we book the correct
            // discount entry ourselves below, since this is not a cash top-up.
            $this->wallet->credit(
                $customer,
                $credit,
                WalletTransactionTypeEnum::Topup,
                __('api.loyalty_redeem_wallet_memo', ['points' => $points]),
                $locked->getKey(),
                SystemAccountEnum::Cash,
                postAccounting: false,
            );

            $this->postRedemption($customer, $credit, $transaction->getKey());

            return $locked;
        });

        return [
            'points' => round($points, 2),
            'wallet_credit' => $credit,
            'points_balance' => round((float) $account->points_balance, 2),
            'wallet_balance' => round((float) $customer->fresh()->wallet_balance, 2),
        ];
    }

    /**
     * The loyalty accounts of the caller's branches, richest first (up to 100).
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, LoyaltyAccount>
     */
    public function accounts(int $organizationId, array $branchIds): Collection
    {
        return LoyaltyAccount::query()
            ->whereHas('customer', fn ($q) => $q->forOrganization($organizationId)->whereIn('branch_id', $branchIds))
            ->with('customer:id,name,phone')
            ->orderByDesc('points_balance')
            ->limit(100)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function accountFor(Customer $customer, LoyaltyProgram $program): LoyaltyAccount
    {
        return LoyaltyAccount::query()->firstOrCreate(
            ['customer_id' => $customer->getKey()],
            ['program_id' => $program->getKey(), 'points_balance' => 0, 'lifetime_points' => 0],
        );
    }

    private function writeTransaction(LoyaltyAccount $account, LoyaltyTransactionTypeEnum $type, float $points, string $note): LoyaltyTransaction
    {
        return $account->transactions()->create([
            'type' => $type->value,
            'points' => $points,
            'note' => $note,
        ]);
    }

    /**
     * Book the redemption: the wallet now holds value the ledger must recognise, taken
     * as a sales discount against a deferred-revenue liability. Idempotent on the points
     * transaction id.
     */
    private function postRedemption(Customer $customer, float $credit, int $transactionId): void
    {
        $organizationId = $customer->organization_id;

        $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::Manual,
            'ref_type' => 'LoyaltyRedemption',
            'ref_id' => $transactionId,
            'memo' => __('api.loyalty_redeem_note'),
            'branch_id' => $customer->branch_id,
            'lines' => [
                ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::SalesDiscounts)->getKey(), 'debit' => $credit],
                ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::DeferredRevenue)->getKey(), 'credit' => $credit],
            ],
        ]);
    }
}
