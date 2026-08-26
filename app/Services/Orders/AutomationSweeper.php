<?php

namespace App\Services\Orders;

use App\Enum\Messaging\WaEventEnum;
use App\Enum\Orders\OrderPriorityEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Models\AutomationSetting;
use App\Models\Order;
use App\Services\Messaging\WaService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Advances orders that have sat past their configured age threshold straight to READY.
 *
 * Automatic promotion only ever reaches READY (never Delivered), takes the maximum delay
 * across the order's service types, and fires the order-ready WhatsApp event best-effort.
 */
class AutomationSweeper
{
    private const CANDIDATES_PER_ORG = 500;

    private const DEFAULT_DELAYS = ['normal' => 180, 'express' => 30];

    public function __construct(private readonly WaService $wa) {}

    /**
     * One pass over every organization with automation enabled.
     *
     * @return array{orgs: int, scanned: int, advanced: int}
     */
    public function sweep(): array
    {
        $orgs = 0;
        $scanned = 0;
        $advanced = 0;

        AutomationSetting::query()->where('enabled', true)->get()->each(function (AutomationSetting $setting) use (&$orgs, &$scanned, &$advanced) {
            $orgs++;
            [$s, $a] = $this->sweepOrganization($setting);
            $scanned += $s;
            $advanced += $a;
        });

        return ['orgs' => $orgs, 'scanned' => $scanned, 'advanced' => $advanced];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{0: int, 1: int}
     */
    private function sweepOrganization(AutomationSetting $setting): array
    {
        $delays = $setting->delays ?? [];
        $now = CarbonImmutable::now();

        $orders = Order::query()
            ->where('organization_id', $setting->organization_id)
            ->whereNull('archived_at')
            ->whereIn('status', [OrderStatusEnum::Received->value, OrderStatusEnum::Processing->value])
            ->with('items.service:id,service_type')
            ->orderBy('created_at')
            ->limit(self::CANDIDATES_PER_ORG)
            ->get();

        $advanced = 0;

        foreach ($orders as $order) {
            $delayMinutes = $this->resolveDelayMinutes($order, $delays);

            if (CarbonImmutable::instance($order->created_at)->addMinutes($delayMinutes)->lessThan($now)) {
                $this->advance($order);
                $advanced++;
            }
        }

        return [$orders->count(), $advanced];
    }

    private function advance(Order $order): void
    {
        $from = $order->status;

        DB::transaction(function () use ($order, $from) {
            $order->forceFill(['status' => OrderStatusEnum::Ready->value])->save();
            $order->statusHistories()->create([
                'user_id' => null,
                'from_status' => $from->value,
                'to_status' => OrderStatusEnum::Ready->value,
                'at' => now(),
            ]);
        });

        $order->loadMissing('customer:id,name,phone');
        $this->wa->trigger($order->organization_id, WaEventEnum::OrderReady, $order->customer?->phone, [
            'name' => $order->customer?->name,
            'orderNo' => $order->order_no,
        ], [
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'order_id' => $order->getKey(),
        ]);
    }

    /**
     * The delay threshold: the maximum over the order's service types for its priority.
     *
     * @param  array<string, mixed>  $delays
     */
    private function resolveDelayMinutes(Order $order, array $delays): int
    {
        $priorityKey = $order->priority === OrderPriorityEnum::Express ? 'express' : 'normal';
        $default = (int) ($delays['default'][$priorityKey] ?? self::DEFAULT_DELAYS[$priorityKey]);

        $serviceTypes = $order->items->map(fn ($item) => $item->service?->service_type)->filter()->unique();

        if ($serviceTypes->isEmpty()) {
            return $default;
        }

        return (int) $serviceTypes
            ->map(fn ($type) => (int) ($delays['service_types'][$type instanceof \BackedEnum ? $type->value : $type][$priorityKey] ?? $default))
            ->max();
    }
}
