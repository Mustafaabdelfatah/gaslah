<?php

namespace App\Services\Reports;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Reports\CashMovementTypeEnum;
use App\Models\JournalLine;
use App\Models\Shift;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cashier shifts: opening a float, the live drawer summary, and closing with a variance.
 *
 * The single-open-shift-per-user rule is enforced by a partial unique index (not the
 * pre-check), so a concurrent double-open loses cleanly with a friendly 422. Expected cash
 * includes cash wallet top-ups, which post no Payment row and would otherwise show as a
 * drawer surplus at close.
 */
class ShiftService
{
    public function __construct(private readonly ChartOfAccountsService $chart) {}

    /**
     * Open a shift with a cash float.
     *
     * @return array<string, mixed>
     */
    public function open(int $organizationId, int $branchId, int $userId, float $openingCash): array
    {
        try {
            $shift = Shift::query()->create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'opened_at' => Carbon::now(),
                'opening_float' => round($openingCash, 2),
                'expected_cash' => 0,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.shift_already_open'));
            }

            throw $exception;
        }

        return $this->summarize($shift);
    }

    /**
     * The caller's open shift, if any.
     */
    public function current(int $userId): ?Shift
    {
        return Shift::query()->open()->where('user_id', $userId)->first();
    }

    /**
     * Close a shift, fixing the expected cash and the variance.
     *
     * @return array<string, mixed>
     */
    public function close(Shift $shift, float $actualCash): array
    {
        $summary = $this->summarize($shift);
        $expected = $summary['expected_cash'];
        $variance = round($actualCash - $expected, 2);

        $shift->forceFill([
            'closed_at' => Carbon::now(),
            'expected_cash' => $expected,
            'actual_cash' => round($actualCash, 2),
            'variance' => $variance,
        ])->save();

        return $this->summarize($shift->refresh());
    }

    /**
     * The live drawer summary for a shift.
     *
     * @return array<string, mixed>
     */
    public function summarize(Shift $shift): array
    {
        $byMethod = $this->paymentsByMethod($shift);
        $cashTotal = round((float) ($byMethod[PaymentMethodEnum::Cash->value] ?? 0), 2);
        $cardTotal = round((float) ($byMethod[PaymentMethodEnum::Card->value] ?? 0), 2);
        $otherTotal = round(array_sum($byMethod) - $cashTotal - $cardTotal, 2);

        $movementsIn = round((float) $shift->movements()->where('type', CashMovementTypeEnum::In->value)->sum('amount'), 2);
        $movementsOut = round((float) $shift->movements()->where('type', CashMovementTypeEnum::Out->value)->sum('amount'), 2);
        $cashTopUps = $this->cashTopUps($shift);

        $expected = round((float) $shift->opening_float + $cashTotal + $cashTopUps + $movementsIn - $movementsOut, 2);

        return [
            'id' => $shift->getKey(),
            'status' => $shift->closed_at === null ? 'open' : 'closed',
            'opened_at' => $shift->opened_at,
            'closed_at' => $shift->closed_at,
            'opening_float' => round((float) $shift->opening_float, 2),
            'cash_total' => $cashTotal,
            'card_total' => $cardTotal,
            'other_total' => $otherTotal,
            'cash_top_ups' => $cashTopUps,
            'movements_in' => $movementsIn,
            'movements_out' => $movementsOut,
            'expected_cash' => $shift->closed_at !== null ? round((float) $shift->expected_cash, 2) : $expected,
            'actual_cash' => $shift->actual_cash !== null ? round((float) $shift->actual_cash, 2) : null,
            'variance' => $shift->variance !== null ? round((float) $shift->variance, 2) : null,
            'orders_count' => $this->ordersCount($shift),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Payment totals by method for the shift's drawer (linked and unlinked payments on
     * the branch since opening).
     *
     * @return array<string, float>
     */
    private function paymentsByMethod(Shift $shift): array
    {
        return $this->drawerPayments($shift)
            ->selectRaw('payments.method as method, SUM(payments.amount) as total')
            ->groupBy('payments.method')
            ->pluck('total', 'method')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function ordersCount(Shift $shift): int
    {
        return (int) $this->drawerPayments($shift)->distinct()->count('payments.order_id');
    }

    private function drawerPayments(Shift $shift): Builder
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('orders.branch_id', $shift->branch_id)
            ->where('payments.created_at', '>=', $shift->opened_at)
            ->when($shift->closed_at, fn ($q) => $q->where('payments.created_at', '<=', $shift->closed_at))
            ->where(fn ($q) => $q->where('payments.shift_id', $shift->getKey())->orWhereNull('payments.shift_id'));
    }

    /**
     * Cash wallet top-ups on the branch during the shift — they post to CASH but write no
     * Payment row, so they must be added to expected cash explicitly.
     */
    private function cashTopUps(Shift $shift): float
    {
        $cashAccountId = $this->chart->systemAccount($shift->organization_id, SystemAccountEnum::Cash)->getKey();

        $sum = JournalLine::query()
            ->where('account_id', $cashAccountId)
            ->where('branch_id', $shift->branch_id)
            ->whereHas('entry', function ($q) use ($shift) {
                $q->where('source', JournalSourceEnum::WalletTopup->value)
                    ->where('created_at', '>=', $shift->opened_at)
                    ->when($shift->closed_at, fn ($sub) => $sub->where('created_at', '<=', $shift->closed_at));
            })
            ->sum('debit');

        return round((float) $sum, 2);
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}
