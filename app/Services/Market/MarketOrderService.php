<?php

namespace App\Services\Market;

use App\Enum\Market\MarketOrderStatusEnum;
use App\Enum\Market\MarketPaymentMethodEnum;
use App\Enum\Market\MarketPaymentStatusEnum;
use App\Models\MarketOrder;
use App\Models\MarketProduct;
use App\Models\MarketSupplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placing and progressing a market order.
 *
 * The buyer pays the subtotal; the platform's commission is deducted from the supplier's
 * payout, never added to the buyer's bill. That is why total always equals subtotal.
 */
class MarketOrderService
{
    /**
     * Place an order from a basket of product lines.
     *
     * @param  array<int, array{product_id: int, quantity: float}>  $lines
     * @param  array{organization_id: int, branch_id: ?int, created_by_id: ?int}  $buyer
     */
    public function place(
        array $lines,
        array $buyer,
        MarketPaymentMethodEnum $method,
        ?string $notes = null,
        ?string $address = null,
    ): MarketOrder {
        $quantities = $this->mergeQuantities($lines);
        $products = $this->buyableProducts($quantities->keys()->all());

        // Anything delisted, or from a supplier who has since been suspended, is simply
        // gone — better to refuse the whole basket than to quietly ship part of it.
        abort_if(
            $products->count() !== $quantities->count(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.market_product_unavailable'),
        );

        $supplierIds = $products->pluck('supplier_id')->unique();

        // One supplier per order: each is confirmed, shipped and paid out separately, so a
        // basket spanning two of them is two orders.
        abort_if(
            $supplierIds->count() !== 1,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.market_single_supplier'),
        );

        $items = $this->buildItems($products, $quantities);
        $subtotal = round($items->sum('line_total'), 2);

        /** @var MarketSupplier $supplier */
        $supplier = MarketSupplier::query()->findOrFail($supplierIds->first());
        $commission = $supplier->commission();
        $commissionAmount = $commission['type']->on($subtotal, $commission['value']);

        return DB::transaction(function () use ($buyer, $supplier, $method, $notes, $address, $subtotal, $commission, $commissionAmount, $items) {
            $order = MarketOrder::query()->create([
                'organization_id' => $buyer['organization_id'],
                'branch_id' => $buyer['branch_id'] ?? null,
                'supplier_id' => $supplier->getKey(),
                'status' => MarketOrderStatusEnum::Pending->value,
                'payment_method' => $method->value,
                'payment_status' => MarketPaymentStatusEnum::Unpaid->value,
                'subtotal' => $subtotal,
                'commission_type' => $commission['type']->value,
                'commission_rate' => $commission['type']->rateFor($commission['value']),
                'commission_amount' => $commissionAmount,
                'total' => $subtotal,
                'supplier_payout' => round($subtotal - $commissionAmount, 2),
                'notes' => $notes,
                'address' => $address,
                'created_by_id' => $buyer['created_by_id'] ?? null,
            ]);

            $order->items()->createMany($items->all());

            return $order->load('items', 'supplier:id,name,phone,city');
        });
    }

    /**
     * Move an order along its lifecycle.
     *
     * Only the supplier drives this — a buyer cannot confirm their own order, nor cancel
     * one the supplier has already shipped.
     */
    public function transition(MarketOrder $order, MarketOrderStatusEnum $target): MarketOrder
    {
        abort_unless(
            $order->status->canMoveTo($target),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.market_invalid_transition'),
        );

        $order->forceFill([
            'status' => $target->value,
            // Stamped once, when the goods actually arrive: it is what the supplier's
            // earned payout is counted from.
            'delivered_at' => $target === MarketOrderStatusEnum::Delivered ? Carbon::now() : $order->delivered_at,
        ])->save();

        return $order->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Collapse repeated lines for the same product into one quantity.
     *
     * A basket that lists the same product twice means three of it, not two lines of one —
     * and merging first is what makes the availability count below meaningful.
     *
     * @param  array<int, array{product_id: int, quantity: float}>  $lines
     * @return Collection<int, float>
     */
    private function mergeQuantities(array $lines): Collection
    {
        $merged = collect();

        foreach ($lines as $line) {
            $id = (int) $line['product_id'];
            $merged[$id] = round((float) $merged->get($id, 0) + (float) $line['quantity'], 2);
        }

        return $merged;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return Collection<int, MarketProduct>
     */
    private function buyableProducts(array $productIds): Collection
    {
        return MarketProduct::query()
            ->buyable()
            ->whereIn('id', $productIds)
            ->get();
    }

    /**
     * Price the basket, snapshotting each product as it stands now.
     *
     * @param  Collection<int, MarketProduct>  $products
     * @param  Collection<int, float>  $quantities
     * @return Collection<int, array<string, mixed>>
     */
    private function buildItems(Collection $products, Collection $quantities): Collection
    {
        return $products->map(function (MarketProduct $product) use ($quantities) {
            $quantity = (float) $quantities->get($product->getKey());
            $unitPrice = round((float) $product->price, 2);

            return [
                'product_id' => $product->getKey(),
                'name' => $product->name,
                'unit' => $product->unit,
                'unit_price' => $unitPrice,
                'quantity' => $quantity,
                'line_total' => round($unitPrice * $quantity, 2),
            ];
        })->values();
    }
}
