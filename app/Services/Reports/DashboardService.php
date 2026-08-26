<?php

namespace App\Services\Reports;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The operational dashboard: today's and yesterday's KPIs, the live workflow stages,
 * money collected and outstanding, and the week's revenue series — all in the Riyadh
 * timezone. "Active" means not archived (archival happens on delivery + full payment).
 */
class DashboardService
{
    private const INACTIVE_DAYS = 45;

    private const WALK_IN_PHONE = '0000000000';

    private const DUE_STATUSES = [
        PaymentStatusEnum::Unpaid->value,
        PaymentStatusEnum::Partial->value,
        PaymentStatusEnum::Deferred->value,
    ];

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

        return [
            'orders_today' => (clone $inBranches())->whereBetween('created_at', [$todayStart, $tomorrowStart])->count(),
            'revenue_today' => $this->revenue((clone $inBranches())->whereBetween('created_at', [$todayStart, $tomorrowStart])),
            'orders_yesterday' => (clone $inBranches())->whereBetween('created_at', [$yesterdayStart, $todayStart])->count(),
            'revenue_yesterday' => $this->revenue((clone $inBranches())->whereBetween('created_at', [$yesterdayStart, $todayStart])),

            'inactive_customers' => $this->inactiveCustomers($branchIds, $now),
            'new_customers' => Customer::query()
                ->where('organization_id', $organizationId)
                ->where('phone', '!=', self::WALK_IN_PHONE)
                ->whereBetween('created_at', [$todayStart, $tomorrowStart])
                ->count(),

            'ready_orders' => (clone $active())->where('status', OrderStatusEnum::Ready->value)->count(),
            'late_orders' => (clone $active())
                ->whereIn('status', [OrderStatusEnum::Received->value, OrderStatusEnum::Processing->value, OrderStatusEnum::Ready->value])
                ->whereNotNull('due_at')
                ->where('due_at', '<', $nowUtc)
                ->count(),

            'stages' => [
                'received' => (clone $active())->where('status', OrderStatusEnum::Received->value)->count(),
                'processing' => (clone $active())->where('status', OrderStatusEnum::Processing->value)->count(),
                'ready' => (clone $active())->where('status', OrderStatusEnum::Ready->value)->count(),
                'delivered' => (clone $inBranches())->where('status', OrderStatusEnum::Delivered->value)->whereBetween('delivered_at', [$todayStart, $tomorrowStart])->count(),
            ],

            'collected' => $this->sum((clone $active()), 'paid_total'),
            'outstanding' => round(max(0, $this->sum((clone $active()), 'grand_total') - $this->sum((clone $active()), 'paid_total')), 2),
            'unpaid_count' => (clone $active())->whereIn('payment_status', self::DUE_STATUSES)->count(),
            'unpaid_amount' => $this->outstandingSum((clone $active())->whereIn('payment_status', self::DUE_STATUSES)),
            'archived_count' => (clone $inBranches())->whereNotNull('archived_at')->whereBetween('created_at', [$todayStart, $tomorrowStart])->count(),

            'recent' => (clone $active())->with('customer:id,name')->latest('id')->limit(8)->get()
                ->map(fn (Order $o) => ['id' => $o->getKey(), 'order_no' => $o->order_no, 'customer' => $o->customer?->name, 'status' => $o->status->value, 'grand_total' => round((float) $o->grand_total, 2), 'created_at' => $o->created_at]),
            'unpaid' => (clone $active())->whereIn('payment_status', self::DUE_STATUSES)->with('customer:id,name')->latest('id')->limit(8)->get()
                ->map(fn (Order $o) => ['id' => $o->getKey(), 'order_no' => $o->order_no, 'customer' => $o->customer?->name, 'remaining' => $o->remaining()]),

            'weekly' => $this->weekly($branchIds, $now),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function revenue(Builder $query): float
    {
        return $this->sum($query->where('status', '!=', OrderStatusEnum::Cancelled->value), 'grand_total');
    }

    private function sum(Builder $query, string $column): float
    {
        return round((float) $query->sum($column), 2);
    }

    private function outstandingSum(Builder $query): float
    {
        $row = $query->selectRaw('COALESCE(SUM(grand_total - paid_total),0) as remaining')->first();

        return round(max(0, (float) $row->remaining), 2);
    }

    /**
     * @param  array<int, int>  $branchIds
     */
    private function inactiveCustomers(array $branchIds, CarbonImmutable $now): int
    {
        $cutoff = $now->subDays(self::INACTIVE_DAYS)->utc();

        return Order::query()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->where('customers.phone', '!=', self::WALK_IN_PHONE)
            ->groupBy('orders.customer_id')
            ->havingRaw('MAX(orders.created_at) < ?', [$cutoff])
            ->get(['orders.customer_id'])
            ->count();
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

        Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereNull('archived_at')
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $start->utc())
            ->get(['created_at', 'grand_total'])
            ->each(function (Order $order) use (&$buckets) {
                $key = $this->ranges->dayKey($order->created_at);
                if (isset($buckets[$key])) {
                    $buckets[$key]['revenue'] = round($buckets[$key]['revenue'] + (float) $order->grand_total, 2);
                }
            });

        return array_values($buckets);
    }
}
