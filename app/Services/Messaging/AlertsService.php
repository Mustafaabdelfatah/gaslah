<?php

namespace App\Services\Messaging;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Models\DeliveryRequest;
use App\Models\InventoryItem;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Live operational alerts for an organization, derived from current data (no table).
 */
class AlertsService
{
    private const LAPSED_DAYS = 45;

    private const WALK_IN_PHONE = '0000000000';

    private const ITEM_CAP = 20;

    private const DUE_STATUSES = [
        PaymentStatusEnum::Unpaid->value,
        PaymentStatusEnum::Partial->value,
        PaymentStatusEnum::Deferred->value,
    ];

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    public function build(array $branchIds, int $organizationId): array
    {
        $groups = [
            $this->portalDelivery($branchIds),
            $this->late($branchIds),
            $this->unpaid($branchIds),
            $this->subExpiry(),
            $this->lowStock($organizationId, $branchIds),
            $this->lapsed($branchIds),
        ];

        return [
            'total' => array_sum(array_column($groups, 'count')),
            'groups' => $groups,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Groups
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    private function portalDelivery(array $branchIds): array
    {
        $query = DeliveryRequest::query()
            ->inBranches($branchIds)
            ->where('source', DeliverySourceEnum::Portal->value)
            ->where('status', DeliveryStatusEnum::Requested->value);

        return $this->group('portal_delivery', 'طلبات توصيل جديدة من البوابة', 'info', 'truck', $query->count(),
            (clone $query)->latest('id')->limit(self::ITEM_CAP)->get(['id', 'type', 'address', 'created_at']));
    }

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    private function late(array $branchIds): array
    {
        $query = $this->activeOrders($branchIds)
            ->whereIn('status', [OrderStatusEnum::Received->value, OrderStatusEnum::Processing->value, OrderStatusEnum::Ready->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<', CarbonImmutable::now());

        return $this->group('late', 'طلبات متأخّرة عن موعدها', 'warning', 'clock', $query->count(),
            (clone $query)->latest('due_at')->limit(self::ITEM_CAP)->get(['id', 'order_no', 'status', 'due_at']));
    }

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    private function unpaid(array $branchIds): array
    {
        $query = $this->activeOrders($branchIds)->whereIn('payment_status', self::DUE_STATUSES);
        $amount = round((float) (clone $query)->selectRaw('COALESCE(SUM(grand_total - paid_total),0) as remaining')->value('remaining'), 2);

        $group = $this->group('unpaid', 'سلال غير مدفوعة', 'warning', 'wallet', $query->count(),
            (clone $query)->latest('id')->limit(self::ITEM_CAP)->get(['id', 'order_no', 'grand_total', 'paid_total']));
        $group['amount'] = max(0, $amount);

        return $group;
    }

    /**
     * @return array<string, mixed>
     */
    private function subExpiry(): array
    {
        // The platform subscription entity lands with the platform console (Phase 9);
        // until then there is nothing to derive here.
        return $this->group('sub_expiry', 'اشتراك المنصّة على وشك الانتهاء', 'critical', 'calendar', 0, collect());
    }

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    private function lowStock(int $organizationId, array $branchIds): array
    {
        $query = InventoryItem::query()
            ->forOrganization($organizationId)
            ->inBranches($branchIds)
            ->where('is_active', true)
            ->lowStock();

        return $this->group('low_stock', 'أصناف مخزون منخفض', 'warning', 'box', $query->count(),
            (clone $query)->orderBy('name')->limit(self::ITEM_CAP)->get(['id', 'name', 'quantity', 'reorder_level']));
    }

    /**
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    private function lapsed(array $branchIds): array
    {
        $cutoff = CarbonImmutable::now()->subDays(self::LAPSED_DAYS);

        $base = Order::query()
            ->join('customers', 'customers.id', '=', 'orders.customer_id')
            ->whereIn('orders.branch_id', $branchIds)
            ->where('customers.phone', '!=', self::WALK_IN_PHONE)
            ->groupBy('orders.customer_id')
            ->havingRaw('MAX(orders.created_at) < ?', [$cutoff]);

        $count = (clone $base)->get(['orders.customer_id'])->count();

        $items = (clone $base)
            ->selectRaw('orders.customer_id as id, customers.name as name, customers.phone as phone, MAX(orders.created_at) as last_at')
            ->orderByRaw('MAX(orders.created_at) asc')
            ->limit(self::ITEM_CAP)
            ->get();

        return $this->group('lapsed', 'عملاء متعثّرون (>45 يوماً)', 'info', 'user', $count, $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, int>  $branchIds
     */
    private function activeOrders(array $branchIds): Builder
    {
        return Order::query()
            ->whereIn('branch_id', $branchIds)
            ->whereNull('archived_at')
            ->where('status', '!=', OrderStatusEnum::Cancelled->value);
    }

    /**
     * @return array<string, mixed>
     */
    private function group(string $key, string $title, string $tone, string $icon, int $count, mixed $items): array
    {
        return [
            'key' => $key,
            'title' => $title,
            'count' => $count,
            'tone' => $tone,
            'icon' => $icon,
            'items' => $items,
        ];
    }
}
