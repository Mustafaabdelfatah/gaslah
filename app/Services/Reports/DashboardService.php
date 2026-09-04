<?php

namespace App\Services\Reports;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The operational dashboard: today's and yesterday's KPIs, the live workflow stages,
 * money collected and outstanding, and the week's revenue series — all in the Riyadh
 * timezone. "Active" means not archived (archival happens on delivery + full payment).
 */
class DashboardService
{
    private const INACTIVE_DAYS = 45;

    private const WALK_IN_PHONE = '0000000000';

    public function __construct(private readonly ReportRangeService $ranges) {}

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    public function build(array $branchIds, int $organizationId): array
    {
        $now = CarbonImmutable::now(ReportRangeService::TIMEZONE);
        $todayStart = $now->startOfDay()->utc();
        $tomorrowStart = $now->startOfDay()->addDay()->utc();
        $yesterdayStart = $now->startOfDay()->subDay()->utc();
        $nowUtc = $now->utc();

        $inBranches = fn () => Order::query()->whereIn('branch_id', $branchIds);
        $active = fn () => $inBranches()->whereNull('archived_at')->where('status', '!=', OrderStatusEnum::Cancelled->value);
        $due = fn () => (clone $active())->outstanding();

        $daily = $inBranches()
            ->where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<', $tomorrowStart)
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as orders_today,
                 SUM(CASE WHEN created_at >= ? AND created_at < ? AND status != ? THEN grand_total ELSE 0 END) as revenue_today,
                 SUM(CASE WHEN created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as orders_yesterday,
                 SUM(CASE WHEN created_at >= ? AND created_at < ? AND status != ? THEN grand_total ELSE 0 END) as revenue_yesterday',
                [
                    $todayStart, $tomorrowStart,
                    $todayStart, $tomorrowStart, OrderStatusEnum::Cancelled->value,
                    $yesterdayStart, $todayStart,
                    $yesterdayStart, $todayStart, OrderStatusEnum::Cancelled->value,
                ],
            )
            ->first();

        $dueStatuses = [
            PaymentStatusEnum::Unpaid->value,
            PaymentStatusEnum::Partial->value,
            PaymentStatusEnum::Deferred->value,
        ];
        $activeSummary = $active()
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as ready_orders,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as received_orders,
                 SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as processing_orders,
                 SUM(CASE WHEN due_at IS NOT NULL AND due_at < ? AND status IN (?, ?, ?) THEN 1 ELSE 0 END) as late_orders,
                 COALESCE(SUM(paid_total), 0) as collected,
                 COALESCE(SUM(grand_total), 0) as billed,
                 SUM(CASE WHEN payment_status IN (?, ?, ?) AND paid_total < grand_total THEN 1 ELSE 0 END) as unpaid_count,
                 COALESCE(SUM(CASE WHEN payment_status IN (?, ?, ?) AND paid_total < grand_total THEN grand_total - paid_total ELSE 0 END), 0) as unpaid_amount',
                [
                    OrderStatusEnum::Ready->value,
                    OrderStatusEnum::Received->value,
                    OrderStatusEnum::Processing->value,
                    $nowUtc,
                    OrderStatusEnum::Received->value,
                    OrderStatusEnum::Processing->value,
                    OrderStatusEnum::Ready->value,
                    ...$dueStatuses,
                    ...$dueStatuses,
                ],
            )
            ->first();

        $completed = $inBranches()
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND delivered_at >= ? AND delivered_at < ? THEN 1 ELSE 0 END) as delivered_today,
                 SUM(CASE WHEN archived_at IS NOT NULL AND created_at >= ? AND created_at < ? THEN 1 ELSE 0 END) as archived_today',
                [OrderStatusEnum::Delivered->value, $todayStart, $tomorrowStart, $todayStart, $tomorrowStart],
            )
            ->first();

        $collected = round((float) $activeSummary->collected, 2);
        $billed = round((float) $activeSummary->billed, 2);

        return [
            'orders_today' => (int) $daily->orders_today,
            'revenue_today' => round((float) $daily->revenue_today, 2),
            'orders_yesterday' => (int) $daily->orders_yesterday,
            'revenue_yesterday' => round((float) $daily->revenue_yesterday, 2),

            'inactive_customers' => $this->inactiveCustomers($branchIds, $now),
            'new_customers' => Customer::query()
                ->where('organization_id', $organizationId)
                ->where('phone', '!=', self::WALK_IN_PHONE)
                ->whereBetween('created_at', [$todayStart, $tomorrowStart])
                ->count(),

            'ready_orders' => (int) $activeSummary->ready_orders,
            'late_orders' => (int) $activeSummary->late_orders,

            'stages' => [
                'received' => (int) $activeSummary->received_orders,
                'processing' => (int) $activeSummary->processing_orders,
                'ready' => (int) $activeSummary->ready_orders,
                'delivered' => (int) $completed->delivered_today,
            ],

            'collected' => $collected,
            'outstanding' => round(max(0, $billed - $collected), 2),
            'unpaid_count' => (int) $activeSummary->unpaid_count,
            'unpaid_amount' => round(max(0, (float) $activeSummary->unpaid_amount), 2),
            'archived_count' => (int) $completed->archived_today,

            'recent' => (clone $active())->select(['id', 'customer_id', 'order_no', 'status', 'grand_total', 'created_at'])
                ->with('customer:id,name')->latest('id')->limit(8)->get()
                ->map(fn (Order $o) => ['id' => $o->getKey(), 'order_no' => $o->order_no, 'customer' => $o->customer?->name, 'status' => $o->status->value, 'grand_total' => round((float) $o->grand_total, 2), 'created_at' => $o->created_at]),
            'unpaid' => $due()->select(['id', 'customer_id', 'order_no', 'grand_total', 'paid_total'])
                ->with('customer:id,name')->latest('id')->limit(8)->get()
                ->map(fn (Order $o) => ['id' => $o->getKey(), 'order_no' => $o->order_no, 'customer' => $o->customer?->name, 'remaining' => $o->remaining()]),

            'weekly' => $this->weekly($branchIds, $now),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    /**
     * @param  array<int, int>  $branchIds
     */
    private function inactiveCustomers(array $branchIds, CarbonImmutable $now): int
    {
        $cutoff = $now->subDays(self::INACTIVE_DAYS)->utc();

        $inactive = Order::query()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->where('customers.phone', '!=', self::WALK_IN_PHONE)
            ->groupBy('orders.customer_id')
            ->havingRaw('MAX(orders.created_at) < ?', [$cutoff])
            ->select('orders.customer_id');

        return DB::query()->fromSub($inactive, 'inactive_customers')->count();
    }

    /**
     * A 7-day revenue series (active, non-cancelled) bucketed by Riyadh day.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, array{day: string, revenue: float}>
     */
    private function weekly(array $branchIds, CarbonImmutable $now): array
    {
        $start = $now->startOfDay()->subDays(6);
        $buckets = [];
        for ($day = $start; $day->lessThanOrEqualTo($now->startOfDay()); $day = $day->addDay()) {
            $buckets[$day->format('Y-m-d')] = ['day' => $day->format('m-d'), 'revenue' => 0.0];
        }

        $dayExpression = $this->ranges->localDateExpression('orders.created_at');
        Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereNull('archived_at')
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $start->utc())
            ->selectRaw("{$dayExpression} as day, COALESCE(SUM(grand_total), 0) as revenue")
            ->groupByRaw($dayExpression)
            ->get()
            ->each(function ($row) use (&$buckets) {
                $key = (string) $row->day;
                if (! isset($buckets[$key])) {
                    return;
                }

                $buckets[$key]['revenue'] = round((float) $row->revenue, 2);
            });

        return array_values($buckets);
    }
}
