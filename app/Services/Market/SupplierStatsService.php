<?php

namespace App\Services\Market;

use App\Enum\Market\MarketOrderStatusEnum;
use App\Models\MarketOrder;
use App\Models\MarketProduct;
use App\Models\MarketSupplier;

/**
 * The headline numbers a supplier sees when they open their portal.
 */
class SupplierStatsService
{
    /**
     * @return array{
     *     products: array{total: int, active: int},
     *     orders: array<string, int>,
     *     earnings: array{delivered_sales: float, commission: float, payout: float},
     * }
     */
    public function summary(MarketSupplier $supplier): array
    {
        return [
            'products' => $this->productCounts($supplier),
            'orders' => $this->orderCounts($supplier),
            'earnings' => $this->earnings($supplier),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{total: int, active: int}
     */
    private function productCounts(MarketSupplier $supplier): array
    {
        // One pass over the catalogue rather than two counts.
        $row = MarketProduct::query()
            ->where('supplier_id', $supplier->getKey())
            ->selectRaw('COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active')
            ->first();

        return [
            'total' => (int) ($row?->total ?? 0),
            'active' => (int) ($row?->active ?? 0),
        ];
    }

    /**
     * Every status is present, zero included, so the portal's tabs do not flicker in and
     * out as orders move.
     *
     * @return array<string, int>
     */
    private function orderCounts(MarketSupplier $supplier): array
    {
        $counts = MarketOrder::query()
            ->forSupplier($supplier->getKey())
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $byStatus = [];

        foreach (MarketOrderStatusEnum::cases() as $status) {
            $byStatus[$status->value] = (int) $counts->get($status->value, 0);
        }

        return ['total' => array_sum($byStatus), ...$byStatus];
    }

    /**
     * Counted from delivered orders only: what has actually been handed over is what the
     * supplier has earned, whatever else is still in flight.
     *
     * @return array{delivered_sales: float, commission: float, payout: float}
     */
    private function earnings(MarketSupplier $supplier): array
    {
        $row = MarketOrder::query()
            ->forSupplier($supplier->getKey())
            ->delivered()
            ->selectRaw('COALESCE(SUM(subtotal), 0) as sales, COALESCE(SUM(commission_amount), 0) as commission, COALESCE(SUM(supplier_payout), 0) as payout')
            ->first();

        return [
            'delivered_sales' => round((float) ($row?->sales ?? 0), 2),
            'commission' => round((float) ($row?->commission ?? 0), 2),
            'payout' => round((float) ($row?->payout ?? 0), 2),
        ];
    }
}
