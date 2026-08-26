<?php

namespace Tests\Feature\Orders;

use App\Services\Orders\OrderPricingService;
use PHPUnit\Framework\TestCase;

class OrderPricingTest extends TestCase
{
    private OrderPricingService $pricing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pricing = new OrderPricingService;
    }

    public function test_tax_is_charged_on_the_net_after_discount(): void
    {
        // 100 subtotal, 10 fixed discount → net 90, tax 15% = 13.50, grand 103.50.
        $totals = $this->pricing->computeTotals(
            [['unit_price' => 100, 'quantity' => 1]],
            expressSurcharge: 0,
            discount: ['type' => 'fixed', 'value' => 10],
            taxRate: 15,
        );

        $this->assertSame(100.0, $totals['subtotal']);
        $this->assertSame(10.0, $totals['discount_total']);
        $this->assertSame(90.0, $totals['taxable_base']);
        $this->assertSame(13.5, $totals['tax_total']);
        $this->assertSame(103.5, $totals['grand_total']);
    }

    public function test_a_percentage_discount_is_clamped_to_100(): void
    {
        // A 150% discount is clamped to 100%, taking the whole subtotal — never more.
        $totals = $this->pricing->computeTotals(
            [['unit_price' => 50, 'quantity' => 2]],
            expressSurcharge: 0,
            discount: ['type' => 'percent', 'value' => 150],
            taxRate: 15,
        );

        $this->assertSame(100.0, $totals['discount_total']);
        $this->assertSame(0.0, $totals['taxable_base']);
        $this->assertSame(0.0, $totals['grand_total']);
    }

    public function test_a_fixed_discount_never_exceeds_the_subtotal(): void
    {
        // A discount larger than the subtotal is capped; the total never goes negative.
        $totals = $this->pricing->computeTotals(
            [['unit_price' => 30, 'quantity' => 1]],
            expressSurcharge: 0,
            discount: ['type' => 'fixed', 'value' => 100],
            taxRate: 15,
        );

        $this->assertSame(30.0, $totals['discount_total']);
        $this->assertSame(0.0, $totals['grand_total']);
    }

    public function test_the_cart_express_surcharge_is_added_to_the_subtotal(): void
    {
        $totals = $this->pricing->computeTotals(
            [['unit_price' => 100, 'quantity' => 1]],
            expressSurcharge: 20,
            discount: null,
            taxRate: 15,
        );

        // 100 + 20 surcharge = 120 subtotal, tax 18, grand 138.
        $this->assertSame(120.0, $totals['subtotal']);
        $this->assertSame(138.0, $totals['grand_total']);
    }

    public function test_totals_are_rounded_to_two_places(): void
    {
        $totals = $this->pricing->computeTotals(
            [['unit_price' => 33.33, 'quantity' => 3]],
            expressSurcharge: 0,
            discount: null,
            taxRate: 15,
        );

        $this->assertSame(99.99, $totals['subtotal']);
        $this->assertSame(114.99, $totals['grand_total']);
    }
}
