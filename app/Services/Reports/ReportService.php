<?php

namespace App\Services\Reports;

use App\Enum\Orders\OrderStatusEnum;
use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only sales reporting.
 *
 * Revenue is the sum of grand_total over non-cancelled orders in the range (the shared
 * definition), except the cancellation rate which counts every status. Simple totals are
 * aggregated in SQL; the daily series is bucketed in PHP in the Riyadh timezone.
 */
class ReportService
{
    /**
     * Arabic labels and the fixed display order for the payment breakdown.
     */
    private const METHOD_ORDER = ['cash', 'card', 'online', 'transfer', 'wallet', 'subscription', 'deferred'];

    private const METHOD_LABELS = [
        'cash' => 'نقدي', 'card' => 'بطاقة', 'online' => 'أونلاين',
        'transfer' => 'تحويل', 'wallet' => 'محفظة', 'subscription' => 'اشتراك', 'deferred' => 'آجل',
    ];

    public function __construct(private readonly ReportRangeService $ranges) {}

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    public function sales(array $branchIds, array $range): array
    {
        $summary = $this->base($branchIds, $range)
            ->selectRaw('COUNT(*) as orders, COALESCE(SUM(grand_total),0) as revenue, COALESCE(SUM(paid_total),0) as collected')
            ->first();

        $orders = (int) $summary->orders;
        $revenue = round((float) $summary->revenue, 2);
        $collected = round((float) $summary->collected, 2);

        return [
            'summary' => [
                'orders' => $orders,
                'revenue' => $revenue,
                'collected' => $collected,
                'outstanding' => round(max(0, $revenue - $collected), 2),
                'avg_ticket' => $orders > 0 ? round($revenue / $orders, 2) : 0,
            ],
            'by_day' => $this->byDay($branchIds, $range),
            'by_status' => $this->byStatus($branchIds, $range),
            'by_payment_method' => $this->byPaymentMethod($branchIds, $range, $collected),
        ];
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array{services: array<int, mixed>, products: array<int, mixed>}
     */
    public function topProducts(array $branchIds, array $range): array
    {
        $lines = fn () => DB::table('order_items')
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->join('services', 'services.id', '=', 'order_items.service_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->where('orders.status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('orders.created_at', '>=', $range['from_utc'])
            ->where('orders.created_at', '<', $range['to_exclusive_utc']);

        $services = (clone $lines())
            ->selectRaw('services.name as name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as revenue')
            ->groupBy('services.name')
            ->orderByDesc('revenue')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => round((float) $r->quantity, 2), 'revenue' => round((float) $r->revenue, 2)]);

        $products = (clone $lines())
            ->leftJoin('products', 'products.id', '=', 'services.product_id')
            ->selectRaw("COALESCE(products.name, 'غير محدد') as name, SUM(order_items.quantity) as quantity, SUM(order_items.line_total) as revenue")
            ->groupByRaw("COALESCE(products.name, 'غير محدد')")
            ->orderByDesc('revenue')
            ->limit(15)
            ->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => round((float) $r->quantity, 2), 'revenue' => round((float) $r->revenue, 2)]);

        return ['services' => $services, 'products' => $products];
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<int, mixed>
     */
    public function topCustomers(array $branchIds, array $range, int $limit): array
    {
        return $this->base($branchIds, $range)
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereNotNull('orders.customer_id')
            ->selectRaw('orders.customer_id as customer_id, customers.name as name, customers.phone as phone, COUNT(*) as orders_count, SUM(orders.grand_total) as revenue, SUM(orders.paid_total) as collected')
            ->groupBy('orders.customer_id', 'customers.name', 'customers.phone')
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'customer_id' => (int) $r->customer_id,
                'name' => $r->name,
                'phone' => $r->phone,
                'orders_count' => (int) $r->orders_count,
                'revenue' => round((float) $r->revenue, 2),
                'collected' => round((float) $r->collected, 2),
            ])->all();
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    public function cancellationRate(array $branchIds, array $range): array
    {
        $row = Order::query()
            ->inBranches($branchIds)
            ->where('created_at', '>=', $range['from_utc'])
            ->where('created_at', '<', $range['to_exclusive_utc'])
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as cancelled', [OrderStatusEnum::Cancelled->value])
            ->first();

        $total = (int) $row->total;
        $cancelled = (int) $row->cancelled;

        return [
            'total_orders' => $total,
            'cancelled_orders' => $cancelled,
            'active_orders' => $total - $cancelled,
            'rate' => $total > 0 ? round($cancelled / $total * 100, 2) : 0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Non-cancelled orders of the branches within the range.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     */
    private function base(array $branchIds, array $range): Builder
    {
        // Columns are qualified so the query stays valid when a caller joins another table.
        return Order::query()
            ->whereIn('orders.branch_id', $branchIds)
            ->where('orders.status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('orders.created_at', '>=', $range['from_utc'])
            ->where('orders.created_at', '<', $range['to_exclusive_utc']);
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<int, array{day: string, orders: int, revenue: float}>
     */
    private function byDay(array $branchIds, array $range): array
    {
        $buckets = [];
        foreach ($range['days'] as $day) {
            $buckets[$day] = ['day' => $day, 'orders' => 0, 'revenue' => 0.0];
        }

        $this->base($branchIds, $range)
            ->get(['created_at', 'grand_total'])
            ->each(function (Order $order) use (&$buckets) {
                $day = $this->ranges->dayKey($order->created_at);
                if (isset($buckets[$day])) {
                    $buckets[$day]['orders']++;
                    $buckets[$day]['revenue'] = round($buckets[$day]['revenue'] + (float) $order->grand_total, 2);
                }
            });

        return array_values($buckets);
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<int, array{status: string, orders: int, revenue: float}>
     */
    private function byStatus(array $branchIds, array $range): array
    {
        return $this->base($branchIds, $range)
            ->selectRaw('status, COUNT(*) as orders, SUM(grand_total) as revenue')
            ->groupBy('status')
            ->orderByDesc('revenue')
            ->get()
            ->map(fn ($r) => [
                'status' => $r->status instanceof OrderStatusEnum ? $r->status->value : $r->status,
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 2),
            ])->all();
    }

    /**
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $range
     * @return array<int, array{name: string, count: int, revenue: float}>
     */
    private function byPaymentMethod(array $branchIds, array $range, float $collected): array
    {
        $payments = DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->where('orders.status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('orders.created_at', '>=', $range['from_utc'])
            ->where('orders.created_at', '<', $range['to_exclusive_utc']);

        $rows = [];
        $totalPayments = 0.0;

        // Non-gateway payments grouped by method; gateway card payments become "online".
        (clone $payments)->where('payments.via_gateway', false)
            ->selectRaw('payments.method as method, COUNT(*) as count, SUM(payments.amount) as revenue')
            ->groupBy('payments.method')
            ->get()
            ->each(function ($r) use (&$rows, &$totalPayments) {
                $rows[$r->method] = ['count' => (int) $r->count, 'revenue' => round((float) $r->revenue, 2)];
                $totalPayments += (float) $r->revenue;
            });

        $online = (clone $payments)->where('payments.via_gateway', true)
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(payments.amount),0) as revenue')
            ->first();
        if ((int) $online->count > 0) {
            $rows['online'] = ['count' => (int) $online->count, 'revenue' => round((float) $online->revenue, 2)];
            $totalPayments += (float) $online->revenue;
        }

        // Subscription coverage is derived: collected minus every recorded payment.
        $subCovered = round($collected - $totalPayments, 2);
        if ($subCovered > 0) {
            $subCount = (int) $this->base($branchIds, $range)->whereNotNull('subscription_id')->count();
            $rows['subscription'] = ['count' => $subCount, 'revenue' => $subCovered];
        }

        $ordered = [];
        foreach (self::METHOD_ORDER as $key) {
            if (isset($rows[$key])) {
                $ordered[] = ['name' => self::METHOD_LABELS[$key], 'count' => $rows[$key]['count'], 'revenue' => $rows[$key]['revenue']];
            }
        }

        return $ordered;
    }
}
