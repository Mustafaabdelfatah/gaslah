<?php

namespace App\Services\Reports;

use App\Enum\Orders\OrderStatusEnum;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Advanced analytics over a period compared against the immediately preceding period of
 * the same length. Day/hour bucketing is done in the Riyadh timezone.
 */
class AnalyticsService
{
    private const CHURN_DAYS = 60;

    public function __construct(private readonly ReportRangeService $ranges) {}

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    public function build(array $branchIds, array $range): array
    {
        $periodDays = $range['period_days'];
        $prior = $this->priorRange($range, $periodDays);

        $summary = $this->summary($branchIds, $range, $prior);
        $revenue = round((float) $summary->revenue, 2);
        $priorRevenue = round((float) $summary->prior_revenue, 2);
        $collected = round((float) $summary->collected, 2);
        $orders = (int) $summary->orders;

        $dailyRevenue = $this->dailyRevenue(
            $branchIds,
            $prior['from_utc'],
            $range['to_exclusive_utc'],
        );
        $currentDaily = $this->dailySeries($dailyRevenue, $range['days']);
        $priorDaily = $this->dailySeries($dailyRevenue, $prior['days']);
        $churnRisk = $this->churnRisk($branchIds);

        return [
            // The window the server actually used, which is not always the one asked
            // for: ReportRangeService clamps anything wider than a year.
            'from' => $range['from_local']->format('Y-m-d'),
            'to' => $range['to_inclusive_local']->format('Y-m-d'),

            'summary' => [
                'revenue' => $revenue,
                'prior_revenue' => $priorRevenue,
                'revenue_delta' => $priorRevenue > 0 ? round(($revenue - $priorRevenue) / $priorRevenue * 100, 2) : 0,
                'orders' => $orders,
                'prior_orders' => (int) $summary->prior_orders,
                'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0,
                'collected' => $collected,
                'outstanding' => round(max(0, $revenue - $collected), 2),
                'repeat_rate' => $this->repeatRate($branchIds, $range),
                'churn_risk_count' => count($churnRisk),
                'forecast_next_week' => $this->forecast(array_values($currentDaily)),
            ],
            'trend' => $this->trend($range['days'], $currentDaily, array_values($priorDaily)),
            'heatmap' => $this->heatmap($branchIds, $range),
            'service_mix' => $this->serviceMix($branchIds, $range),
            'churn_risk' => $churnRisk,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function summary(array $branchIds, array $range, array $prior): object
    {
        return Order::query()
            ->whereIn('orders.branch_id', $branchIds)
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $prior['from_utc'])
            ->where('created_at', '<', $range['to_exclusive_utc'])
            ->selectRaw(
                'SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as orders,
                 COALESCE(SUM(CASE WHEN created_at >= ? THEN grand_total ELSE 0 END), 0) as revenue,
                 COALESCE(SUM(CASE WHEN created_at >= ? THEN paid_total ELSE 0 END), 0) as collected,
                 SUM(CASE WHEN created_at < ? THEN 1 ELSE 0 END) as prior_orders,
                 COALESCE(SUM(CASE WHEN created_at < ? THEN grand_total ELSE 0 END), 0) as prior_revenue',
                [
                    $range['from_utc'],
                    $range['from_utc'],
                    $range['from_utc'],
                    $range['from_utc'],
                    $range['from_utc'],
                ],
            )
            ->first();
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array{from_utc: CarbonImmutable, to_exclusive_utc: CarbonImmutable, days: array<int, string>}
     */
    private function priorRange(array $range, int $periodDays): array
    {
        $priorToExclusiveLocal = $range['from_local'];
        $priorFromLocal = $range['from_local']->subDays($periodDays);

        $days = [];
        for ($day = $priorFromLocal; $day->lessThan($priorToExclusiveLocal); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return [
            'from_utc' => $priorFromLocal->utc(),
            'to_exclusive_utc' => $priorToExclusiveLocal->utc(),
            'days' => $days,
        ];
    }

    /**
     * @param  array<string, float>  $dailyRevenue
     * @param  array<int, string>  $days
     * @return array<string, float>
     */
    private function dailySeries(array $dailyRevenue, array $days): array
    {
        $series = array_fill_keys($days, 0.0);

        foreach ($dailyRevenue as $day => $revenue) {
            if (array_key_exists($day, $series)) {
                $series[$day] = $revenue;
            }
        }

        return $series;
    }

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, float>
     */
    private function dailyRevenue(array $branchIds, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): array
    {
        $dayExpression = $this->ranges->localDateExpression('orders.created_at');

        return Order::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtc)
            ->selectRaw("{$dayExpression} as day, COALESCE(SUM(grand_total), 0) as revenue")
            ->groupByRaw($dayExpression)
            ->pluck('revenue', 'day')
            ->map(fn ($revenue) => round((float) $revenue, 2))
            ->all();
    }

    /**
     * @param  array<int, string>  $days
     * @param  array<string, float>  $currentDaily
     * @param  array<int, float>  $priorValues
     * @return array<int, array{day: string, current: float, prior: float}>
     */
    private function trend(array $days, array $currentDaily, array $priorValues): array
    {
        $current = array_values($currentDaily);
        $trend = [];

        foreach ($days as $i => $day) {
            $trend[] = [
                'day' => $day,
                'current' => $current[$i] ?? 0,
                'prior' => $priorValues[$i] ?? 0,
            ];
        }

        return $trend;
    }

    /**
     * @return array{grid: array<int, array<int, int>>, max: int}
     */
    private function heatmap(array $branchIds, array $range): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;
        [$dayExpression, $hourExpression] = $this->localTimeExpressions('orders.created_at');

        $rows = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $range['from_utc'])
            ->where('created_at', '<', $range['to_exclusive_utc'])
            ->selectRaw("{$dayExpression} as day_of_week, {$hourExpression} as hour_of_day, COUNT(*) as orders")
            ->groupByRaw("{$dayExpression}, {$hourExpression}")
            ->get();

        foreach ($rows as $row) {
            $day = (int) $row->day_of_week;
            $hour = (int) $row->hour_of_day;
            $count = (int) $row->orders;
            $grid[$day][$hour] = $count;
            $max = max($max, $count);
        }

        return ['grid' => $grid, 'max' => $max];
    }

    /** @return array{string, string} */
    private function localTimeExpressions(string $column): array
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => [
                "CAST(strftime('%w', datetime({$column}, '+3 hours')) AS INTEGER)",
                "CAST(strftime('%H', datetime({$column}, '+3 hours')) AS INTEGER)",
            ],
            'pgsql' => [
                "EXTRACT(DOW FROM {$column} + INTERVAL '3 hours')",
                "EXTRACT(HOUR FROM {$column} + INTERVAL '3 hours')",
            ],
            'sqlsrv' => [
                "DATEDIFF(day, '19000107', CAST(DATEADD(hour, 3, {$column}) AS date)) % 7",
                "DATEPART(hour, DATEADD(hour, 3, {$column}))",
            ],
            default => [
                "DAYOFWEEK(DATE_ADD({$column}, INTERVAL 3 HOUR)) - 1",
                "HOUR(DATE_ADD({$column}, INTERVAL 3 HOUR))",
            ],
        };
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<int, array{name: string, quantity: float, revenue: float, share: float}>
     */
    private function serviceMix(array $branchIds, array $range): array
    {
        $rows = DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('services', 'services.id', '=', 'order_items.service_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->where('orders.status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('orders.created_at', '>=', $range['from_utc'])
            ->where('orders.created_at', '<', $range['to_exclusive_utc'])
            ->selectRaw('services.name as name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as revenue, SUM(SUM(order_items.line_total)) OVER () as total_revenue')
            ->groupBy('services.name')
            ->orderByDesc('revenue')
            ->limit(8)
            ->get();

        return $rows->map(fn ($r) => [
            'name' => $r->name,
            'quantity' => round((float) $r->quantity, 2),
            'revenue' => round((float) $r->revenue, 2),
            'share' => (float) $r->total_revenue > 0
                ? round((float) $r->revenue / (float) $r->total_revenue * 100, 2)
                : 0,
        ])->all();
    }

    /**
     * Customers active in the period who also ordered before it began.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     */
    private function repeatRate(array $branchIds, array $range): float
    {
        $currentCustomers = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('customer_id')
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $range['from_utc'])
            ->where('created_at', '<', $range['to_exclusive_utc'])
            ->select('customer_id')
            ->distinct();

        $priorCustomers = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('customer_id')
            ->where('created_at', '<', $range['from_utc'])
            ->select('customer_id')
            ->distinct();

        $row = DB::query()
            ->fromSub($currentCustomers, 'current_customers')
            ->leftJoinSub($priorCustomers, 'prior_customers', 'prior_customers.customer_id', '=', 'current_customers.customer_id')
            ->selectRaw('COUNT(*) as customers, SUM(CASE WHEN prior_customers.customer_id IS NOT NULL THEN 1 ELSE 0 END) as repeats')
            ->first();

        $customers = (int) $row->customers;

        return $customers > 0 ? round((int) $row->repeats / $customers * 100, 2) : 0;
    }

    /**
     * Customers with 2+ orders whose last order is older than 60 days, top 12 by spend.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, array{id: int, name: string|null, phone: string|null, orders: int, spent: float, days_since: int}>
     */
    private function churnRisk(array $branchIds): array
    {
        $now = CarbonImmutable::now();
        $cutoff = $now->subDays(self::CHURN_DAYS);

        $rows = Order::query()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->whereNotNull('orders.customer_id')
            ->where('orders.status', '!=', OrderStatusEnum::Cancelled->value)
            ->selectRaw('orders.customer_id, customers.name, customers.phone, COUNT(*) as orders, SUM(orders.grand_total) as spent, MAX(orders.created_at) as last_at')
            ->groupBy('orders.customer_id', 'customers.name', 'customers.phone')
            ->havingRaw('COUNT(*) >= 2')
            ->havingRaw('MAX(orders.created_at) < ?', [$cutoff])
            ->orderByDesc('spent')
            ->limit(12)
            ->get();

        return $rows->map(fn ($r) => [
            'id' => (int) $r->customer_id,
            'name' => $r->name,
            'phone' => $r->phone,
            'orders' => (int) $r->orders,
            'spent' => round((float) $r->spent, 2),
            'days_since' => (int) CarbonImmutable::parse($r->last_at)->diffInDays($now),
        ])->all();
    }

    /**
     * Next-week revenue forecast: a 50/50 blend of a 7-day moving average and a linear
     * regression projection.
     *
     * @param  array<int, float>  $daily
     */
    private function forecast(array $daily): float
    {
        $n = count($daily);
        if ($n === 0) {
            return 0;
        }

        $ma = round(array_sum(array_slice($daily, -7)) / min(7, $n), 2);
        $last = (float) end($daily);
        $slope = $this->slope($daily);

        $forecast = 0.0;
        for ($i = 1; $i <= 7; $i++) {
            $linear = max(0, $last + $slope * $i);
            $forecast += 0.5 * $linear + 0.5 * $ma;
        }

        return round(max(0, $forecast), 2);
    }

    /**
     * Least-squares slope of a daily series.
     *
     * @param  array<int, float>  $values
     */
    private function slope(array $values): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0;
        }

        $sumX = $sumY = $sumXy = $sumXx = 0.0;
        foreach ($values as $x => $y) {
            $sumX += $x;
            $sumY += $y;
            $sumXy += $x * $y;
            $sumXx += $x * $x;
        }

        $denominator = $n * $sumXx - $sumX * $sumX;

        return $denominator === 0.0 ? 0 : ($n * $sumXy - $sumX * $sumY) / $denominator;
    }
}
