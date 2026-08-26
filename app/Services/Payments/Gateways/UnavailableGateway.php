<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use Symfony\Component\HttpFoundation\Response;

/**
 * The fail-closed gateway: it aborts 503 for every operation. Resolved in production
 * when no full gateway credentials are configured — better to refuse than to mark
 * invoices paid falsely.
 */
class UnavailableGateway implements PaymentGateway
{
    public function __construct(private readonly string $message) {}

    public function provider(): string
    {
        return 'unavailable';
    }

    public function publishableKey(): ?string
    {
        return null;
    }

    public function capture(Order $order, float $amount, string $channel, ?string $paymentRef): string
    {
        abort(Response::HTTP_SERVICE_UNAVAILABLE, $this->message);
    }
}
