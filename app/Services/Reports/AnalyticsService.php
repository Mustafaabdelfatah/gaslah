<?php

namespace App\Services\Reports;

use App\Enum\Orders\OrderStatusEnum;
use App\Models\Customer;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
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

        $current = $this->orders($branchIds, $range['from_utc'], $range['to_exclusive_utc']);
        $priorOrders = $this->orders($branchIds, $prior['from_utc'], $prior['to_exclusive_utc']);

        $revenue = round($current->sum(fn ($o) => (float) $o->grand_total), 2);
        $priorRevenue = round($priorOrders->sum(fn ($o) => (float) $o->grand_total), 2);
        $collected = round($current->sum(fn ($o) => (float) $o->paid_total), 2);
        $orders = $current->count();

        $currentDaily = $this->dailySeries($current, $range['days']);
        $priorDaily = $this->dailySeries($priorOrders, $prior['days']);

        return [
            'summary' => [
                'revenue' => $revenue,
                'prior_revenue' => $priorRevenue,
                'revenue_delta' => $priorRevenue > 0 ? round(($revenue - $priorRevenue) / $priorRevenue * 100, 2) : 0,
                'orders' => $orders,
                'prior_orders' => $priorOrders->count(),
                'aov' => $orders > 0 ? round($revenue / $orders, 2) : 0,
                'collected' => $collected,
                'outstanding' => round(max(0, $revenue - $collected), 2),
                'repeat_rate' => $this->repeatRate($branchIds, $range, $current),
                'churn_risk_count' => count($this->churnRisk($branchIds)),
                'forecast_next_week' => $this->forecast(array_values($currentDaily)),
            ],
            'trend' => $this->trend($range['days'], $currentDaily, array_values($priorDaily)),
            'heatmap' => $this->heatmap($current),
            'service_mix' => $this->serviceMix($branchIds, $range),
            'churn_risk' => $this->churnRisk($branchIds),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, int>  $branchIds
     * @return Collection<int, Order>
     */
    private function orders(array $branchIds, CarbonImmutable $fromUtc, CarbonImmutable $toUtc): Collection
    {
        return Order::query()
            ->whereIn('branch_id', $branchIds)
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $fromUtc)
            ->where('created_at', '<', $toUtc)
            ->get(['created_at', 'grand_total', 'paid_total', 'customer_id']);
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
     * @param  Collection<int, Order>  $orders
     * @param  array<int, string>  $days
     * @return array<string, float>
     */
    private function dailySeries(Collection $orders, array $days): array
    {
        $series = array_fill_keys($days, 0.0);

        foreach ($orders as $order) {
            $key = $this->ranges->dayKey($order->created_at);
            if (array_key_exists($key, $series)) {
                $series[$key] = round($series[$key] + (float) $order->grand_total, 2);
            }
        }

        return $series;
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
     * @param  Collection<int, Order>  $orders
     * @return array{grid: array<int, array<int, int>>, max: int}
     */
    private function heatmap(Collection $orders): array
    {
        $grid = array_fill(0, 7, array_fill(0, 24, 0));
        $max = 0;

        foreach ($orders as $order) {
            $local = CarbonImmutable::instance($order->created_at)->setTimezone(ReportRangeService::TIMEZONE);
            $dow = (int) $local->format('w'); // 0 = Sunday
            $hour = (int) $local->format('G');
            $grid[$dow][$hour]++;
            $max = max($max, $grid[$dow][$hour]);
        }

        return ['grid' => $grid, 'max' => $max];
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
            ->selectRaw('services.name as name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as revenue')
            ->groupBy('services.name')
            ->orderByDesc('revenue')
            ->get();

        $total = round($rows->sum(fn ($r) => (float) $r->revenue), 2);

        return $rows->take(8)->map(fn ($r) => [
            'name' => $r->name,
            'quantity' => round((float) $r->quantity, 2),
            'revenue' => round((float) $r->revenue, 2),
            'share' => $total > 0 ? round((float) $r->revenue / $total * 100, 2) : 0,
        ])->all();
    }

    /**
     * Customers active in the period who also ordered before it began.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @param  Collection<int, Order>  $current
     */
    private function repeatRate(array $branchIds, array $range, Collection $current): float
    {
        $customerIds = $current->pluck('customer_id')->filter()->unique()->values();

        if ($customerIds->isEmpty()) {
            return 0;
        }

        $repeats = Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('customer_id', $customerIds)
            ->where('created_at', '<', $range['from_utc'])
            ->distinct()
            ->count('customer_id');

        return round($repeats / $customerIds->count() * 100, 2);
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
            ->whereIn('branch_id', $branchIds)
            ->whereNotNull('customer_id')
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->selectRaw('customer_id, COUNT(*) as orders, SUM(grand_total) as spent, MAX(created_at) as last_at')
            ->groupBy('customer_id')
            ->havingRaw('COUNT(*) >= 2')
            ->havingRaw('MAX(created_at) < ?', [$cutoff])
            ->orderByDesc('spent')
            ->limit(12)
            ->get();

        $names = Customer::query()->whereIn('id', $rows->pluck('customer_id'))->get(['id', 'name', 'phone'])->keyBy('id');

        return $rows->map(fn ($r) => [
            'id' => (int) $r->customer_id,
            'name' => $names[$r->customer_id]->name ?? null,
            'phone' => $names[$r->customer_id]->phone ?? null,
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
