<?php

namespace App\Services\Catalog;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Services\Loyalty\LoyaltyService;

/**
 * The figures the customer page opens with: what this person has spent with the shop,
 * and what their points are worth. Computed on the single-customer read only — a listing
 * would pay a query per row for numbers nobody is looking at there.
 */
class CustomerContextService
{
    public function __construct(private readonly LoyaltyService $loyalty) {}

    /**
     * @return array{total_orders: int, total_spent: float, avg_basket: float, last_visit: string|null, unpaid_orders_count: int, outstanding_amount: float}
     */
    public function stats(Customer $customer): array
    {
        // Cancelled baskets are excluded everywhere money is summed; a visit that was
        // reversed is not spend.
        $dueStatuses = [
            PaymentStatusEnum::Unpaid->value,
            PaymentStatusEnum::Partial->value,
            PaymentStatusEnum::Deferred->value,
        ];
        $row = $customer->orders()
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->selectRaw(
                'COUNT(*) as total_orders,
                 COALESCE(SUM(grand_total), 0) as total_spent,
                 MAX(created_at) as last_visit,
                 SUM(CASE WHEN payment_status IN (?, ?, ?) AND paid_total < grand_total THEN 1 ELSE 0 END) as unpaid_orders_count,
                 COALESCE(SUM(CASE WHEN payment_status IN (?, ?, ?) AND paid_total < grand_total THEN grand_total - paid_total ELSE 0 END), 0) as outstanding_amount',
                [...$dueStatuses, ...$dueStatuses],
            )
            ->first();

        $totalOrders = (int) $row->total_orders;
        $totalSpent = round((float) $row->total_spent, 2);

        return [
            'total_orders' => $totalOrders,
            'total_spent' => $totalSpent,
            'avg_basket' => $totalOrders > 0 ? round($totalSpent / $totalOrders, 2) : 0.0,
            'last_visit' => $row->last_visit,
            'unpaid_orders_count' => (int) $row->unpaid_orders_count,
            'outstanding_amount' => round((float) $row->outstanding_amount, 2),
        ];
    }

    /**
     * Points and what they convert to. Zeros when the shop runs no programme — the page
     * renders the card either way, and a missing key would read as an error.
     *
     * @return array{points: float, point_value: float, value: float}
     */
    public function loyalty(Customer $customer): array
    {
        $program = $this->loyalty->resolveProgram($customer->organization_id);

        $points = $program->exists
            ? (float) LoyaltyAccount::query()
                ->where('customer_id', $customer->getKey())
                ->where('program_id', $program->getKey())
                ->value('points_balance')
            : 0.0;

        $pointValue = $program->exists ? round((float) $program->point_value, 4) : 0.0;

        return [
            'points' => round($points, 2),
            'point_value' => $pointValue,
            'value' => round($points * $pointValue, 2),
        ];
    }
}
