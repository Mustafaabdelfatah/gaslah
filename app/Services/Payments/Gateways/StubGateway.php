<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use Illuminate\Support\Str;

/**
 * A local-only gateway that makes no network call and treats the charge as captured
 * immediately, returning a fake reference. Never permitted in production.
 */
class StubGateway implements PaymentGateway
{
    public function provider(): string
    {
        return 'stub';
    }

    public function publishableKey(): ?string
    {
        return null;
    }

    public function capture(Order $order, float $amount, string $channel, ?string $paymentRef): string
    {
        return $paymentRef ?: 'poc_'.Str::lower(Str::random(20));
    }
}
