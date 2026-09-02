<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Payment;

/**
 * Builds the aggregate-only parts of the staff order detail read model.
 *
 * Keeping these queries out of the JSON resource prevents an innocent order listing
 * from turning into an N+1 query while the detail screen still gets the same customer
 * basket rollup and unified activity feed as the live product.
 */
class OrderDetailService
{
    /**
     * @param  array<int, int>  $branchIds
     * @return array{customer_stats: ?array<string, mixed>, activity: array<int, array<string, mixed>>}
     */
    public function context(Order $order, array $branchIds): array
    {
        return [
            'customer_stats' => $this->customerStats($order, $branchIds),
            'activity' => $this->activity($order),
        ];
    }

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>|null
     */
    private function customerStats(Order $order, array $branchIds): ?array
    {
        if ($order->customer_id === null) {
            return null;
        }

        $rows = Order::query()
            ->inBranches($branchIds)
            ->where('customer_id', $order->customer_id)
            ->selectRaw('status, COUNT(*) as basket_count, COALESCE(SUM(grand_total), 0) as total')
            ->groupBy('status')
            ->get();

        $byStatus = [];
        $totalOrders = 0;
        $totalSpent = 0.0;

        foreach ($rows as $row) {
            $byStatus[$row->status->value] = (int) $row->basket_count;
            $totalOrders += (int) $row->basket_count;
            $totalSpent += (float) $row->total;
        }

        return [
            'by_status' => $byStatus,
            'total_orders' => $totalOrders,
            'total_spent' => round($totalSpent, 2),
        ];
    }

    /**
     * Creation, status transitions and collections in one newest-first timeline.
     *
     * @return array<int, array<string, mixed>>
     */
    private function activity(Order $order): array
    {
        $events = collect([[
            'type' => 'created',
            'at' => $order->created_at,
            'user' => $order->cashier?->name,
        ]]);

        $events = $events->concat($order->statusHistories->map(fn (OrderStatusHistory $history) => [
            'type' => 'status',
            'at' => $history->at,
            'user' => $history->user?->name,
            'from_status' => $history->from_status?->value,
            'to_status' => $history->to_status->value,
            'note' => $history->note,
        ]));

        $events = $events->concat($order->payments->map(fn (Payment $payment) => [
            'type' => 'payment',
            'at' => $payment->created_at,
            'user' => null,
            'method' => $payment->method->value,
            'amount' => (float) $payment->amount,
            'reference' => $payment->reference,
        ]));

        return $events
            ->sortByDesc(fn (array $event) => $event['at']?->getTimestamp() ?? 0)
            ->values()
            ->all();
    }
}
