<?php

namespace App\Services\Payments;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Models\Customer;
use App\Models\WalletTransaction;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The single gate for every wallet movement.
 *
 * Each credit or debit opens a transaction and reads the balance with a row lock
 * (SELECT … FOR UPDATE) before deciding anything. Reading the balance off a
 * previously loaded model would let two concurrent movements both pass a sufficiency
 * check and overspend, so nothing here trusts an in-memory balance.
 */
class WalletService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
    ) {}

    /**
     * Add value to the wallet.
     *
     * A top-up is the only movement that posts to the ledger (received cash becomes a
     * deferred-revenue liability); a refund restores a liability that a caller records
     * itself, so it passes postAccounting = false.
     *
     * @return array{balance: float, transaction_id: int}
     */
    public function credit(
        Customer $customer,
        float $amount,
        WalletTransactionTypeEnum $type,
        string $note,
        ?int $refId = null,
        SystemAccountEnum $fundingAccount = SystemAccountEnum::Cash,
        bool $postAccounting = true,
        ?int $collectedAtBranchId = null,
    ): array {
        $amount = round($amount, 2);
        $this->assertPositive($amount);

        return DB::transaction(function () use ($customer, $amount, $type, $note, $refId, $fundingAccount, $postAccounting, $collectedAtBranchId) {
            $current = $this->lockedBalance($customer);
            $newBalance = round($current + $amount, 2);

            $transaction = $this->writeMovement($customer, $type, $amount, $newBalance, $refId, $note);

            // Only a genuine top-up recognises new deferred revenue. Without try/catch:
            // if the ledger post fails, the whole credit rolls back, so the wallet never
            // holds value the books do not show.
            if ($postAccounting && $type === WalletTransactionTypeEnum::Topup) {
                $this->postTopUp($customer, $amount, $transaction->getKey(), $fundingAccount, $collectedAtBranchId);
            }

            return ['balance' => $newBalance, 'transaction_id' => $transaction->getKey()];
        });
    }

    /**
     * Draw value from the wallet.
     *
     * The sufficiency check is made against the locked balance, never a preloaded one.
     *
     * @return array{balance: float, transaction_id: int}
     */
    public function debit(Customer $customer, float $amount, string $note, ?int $refId = null): array
    {
        $amount = round($amount, 2);
        $this->assertPositive($amount);

        return DB::transaction(function () use ($customer, $amount, $note, $refId) {
            $current = $this->lockedBalance($customer);

            if ($current < $amount) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.wallet_insufficient_balance'));
            }

            $newBalance = round($current - $amount, 2);
            $transaction = $this->writeMovement($customer, WalletTransactionTypeEnum::Debit, $amount, $newBalance, $refId, $note);

            return ['balance' => $newBalance, 'transaction_id' => $transaction->getKey()];
        });
    }

    /**
     * Recent wallet movements, newest first.
     *
     * @return Collection<int, WalletTransaction>
     */
    public function history(Customer $customer, int $limit = 50): Collection
    {
        return $customer->walletTransactions()
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * The current balance read under a row lock from the database, not the model.
     */
    private function lockedBalance(Customer $customer): float
    {
        $balance = DB::table('customers')
            ->where('id', $customer->getKey())
            ->lockForUpdate()
            ->value('wallet_balance');

        return round((float) $balance, 2);
    }

    private function writeMovement(
        Customer $customer,
        WalletTransactionTypeEnum $type,
        float $amount,
        float $newBalance,
        ?int $refId,
        string $note,
    ): WalletTransaction {
        $transaction = WalletTransaction::query()->create([
            'customer_id' => $customer->getKey(),
            'type' => $type->value,
            'amount' => $amount,
            'balance_after' => $newBalance,
            'ref_id' => $refId,
            'note' => $note,
        ]);

        // Update the stored balance inside the same locked transaction so it never
        // drifts from balance_after.
        DB::table('customers')->where('id', $customer->getKey())->update(['wallet_balance' => $newBalance]);
        $customer->setAttribute('wallet_balance', $newBalance);

        return $transaction;
    }

    private function postTopUp(
        Customer $customer,
        float $amount,
        int $refId,
        SystemAccountEnum $fundingAccount,
        ?int $collectedAtBranchId,
    ): void {
        $organizationId = $customer->organization_id;

        $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::WalletTopup,
            'ref_type' => 'WalletTransaction',
            'ref_id' => $refId,
            'memo' => __('api.wallet_topup_memo', ['name' => $customer->name]),
            // Money belongs to the till that took it, which can differ from the
            // customer's home branch when staff serve several branches.
            'branch_id' => $collectedAtBranchId ?? $customer->branch_id,
            'lines' => [
                ['account_id' => $this->chart->systemAccount($organizationId, $fundingAccount)->getKey(), 'debit' => $amount],
                ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::DeferredRevenue)->getKey(), 'credit' => $amount],
            ],
        ]);
    }

    private function assertPositive(float $amount): void
    {
        if ($amount <= 0) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.wallet_invalid_amount'));
        }
    }
}
