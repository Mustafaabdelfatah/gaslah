<?php

namespace App\Services\Platform;

use App\Models\PlatformExpense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform's own operating costs, and settling up with a partner who fronted one.
 */
class PlatformExpenseService
{
    public function __construct(private readonly PlatformBooks $books) {}

    /**
     * Record a cost and expense it to the platform books in one transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function record(array $data, ?int $adminId): PlatformExpense
    {
        return DB::transaction(function () use ($data, $adminId) {
            $expense = PlatformExpense::query()->create([
                ...$data,
                'date' => $data['date'] ?? Carbon::now()->toDateString(),
                'created_by_id' => $adminId,
                'created_at' => Carbon::now(),
            ]);

            $this->books->postExpense($expense);

            return $expense->refresh();
        });
    }

    /**
     * Mark a partner-funded expense as paid back.
     *
     * The write is a compare-and-swap on reimbursed_at: only a row that is still
     * outstanding is claimed, so two clicks cannot reimburse the same expense twice and
     * quietly pay a partner double. An expense nobody fronted has nothing to settle.
     */
    public function reimburse(PlatformExpense $expense, ?int $adminId): PlatformExpense
    {
        abort_if(
            $expense->paid_by_partner_id === null,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.expense_not_partner_funded'),
        );

        $affected = PlatformExpense::query()
            ->whereKey($expense->getKey())
            ->whereNull('reimbursed_at')
            ->update([
                'reimbursed_at' => Carbon::now(),
                'reimbursed_by_id' => $adminId,
            ]);

        abort_if($affected === 0, Response::HTTP_CONFLICT, __('api.expense_already_reimbursed'));

        return $expense->refresh();
    }

    /**
     * What the platform still owes each partner for costs they fronted.
     *
     * @return array<int, float> keyed by partner id
     */
    public function outstandingByPartner(): array
    {
        return PlatformExpense::query()
            ->outstanding()
            ->groupBy('paid_by_partner_id')
            ->selectRaw('paid_by_partner_id, COALESCE(SUM(amount), 0) as owed')
            ->pluck('owed', 'paid_by_partner_id')
            ->map(fn ($owed) => round((float) $owed, 2))
            ->all();
    }
}
