<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;

/**
 * A payment gateway that captures a card charge for an order.
 *
 * The hosted card form has already charged the card; capture verifies that charge
 * server-side and returns the provider transaction id used as the idempotency key.
 */
interface PaymentGateway
{
    /**
     * The provider name recorded on the charge (moyasar / stub).
     */
    public function provider(): string;

    /**
     * The publishable key the hosted card form authenticates with (public by nature),
     * or null when there is no live gateway.
     */
    public function publishableKey(): ?string;

    /**
     * Verify the charge and return the provider transaction id (or abort on failure).
     */
    public function capture(Order $order, float $amount, string $channel, ?string $paymentRef): string;
}
