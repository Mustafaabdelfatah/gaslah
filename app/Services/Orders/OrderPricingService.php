<?php

namespace App\Services\Orders;

/**
 * Computes order totals on the server.
 *
 * The client's prices are never used: each line's unit price is re-derived from the
 * catalog before this runs, and the arithmetic here mirrors the business formula
 * exactly — tax is charged on the net after discount, and the discount can never
 * exceed the subtotal, so a total or tax can never go negative.
 */
class OrderPricingService
{
    /**
     * @param  array<int, array{unit_price: float, quantity: float}>  $lines  Lines already re-priced from the catalog.
     * @param  array{type: string, value: float}|null  $discount
     * @return array{subtotal: float, discount_total: float, taxable_base: float, tax_total: float, grand_total: float}
     */
    public function computeTotals(array $lines, float $expressSurcharge, ?array $discount, float $taxRate): array
    {
        $lineSum = 0.0;
        foreach ($lines as $line) {
            $lineSum += (float) $line['unit_price'] * (float) $line['quantity'];
        }

        // Cart-level express surcharge is added to the subtotal, separate from a line's
        // own express flag (which already raised its unit price).
        $subtotal = round($lineSum + $expressSurcharge, 2);

        $discountTotal = $this->discountFor($subtotal, $discount);

        $taxableBase = round($subtotal - $discountTotal, 2);
        $taxTotal = round($taxableBase * ($taxRate / 100), 2);
        $grandTotal = round($taxableBase + $taxTotal, 2);

        return [
            'subtotal' => $subtotal,
            'discount_total' => $discountTotal,
            'taxable_base' => $taxableBase,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
        ];
    }

    /**
     * @param  array{type: string, value: float}|null  $discount
     */
    private function discountFor(float $subtotal, ?array $discount): float
    {
        if ($discount === null) {
            return 0.0;
        }

        $value = (float) ($discount['value'] ?? 0);

        $amount = match ($discount['type'] ?? 'fixed') {
            // A percentage is clamped to 0–100 before it is applied.
            'percent' => round($subtotal * (max(0, min(100, $value)) / 100), 2),
            default => round(min($value, $subtotal), 2),
        };

        // A hard ceiling: the discount never exceeds the subtotal.
        return round(min($amount, $subtotal), 2);
    }
}
