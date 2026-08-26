<?php

namespace App\Services\Payments\Gateways;

use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Moyasar gateway. Capture re-fetches the transaction server-side with the secret
 * key and accepts it only when it is paid, the amount matches to the halala, and the
 * currency is SAR. A conflicting metadata order id is rejected; a missing one is
 * tolerated for backward compatibility (the webhook, by contrast, requires it).
 */
class MoyasarGateway implements PaymentGateway
{
    private const READ_TIMEOUT = 20;

    private const CONNECT_TIMEOUT = 5;

    public function __construct(
        private readonly string $secret,
        private readonly ?string $publishable,
        private readonly string $baseUrl,
    ) {}

    public function provider(): string
    {
        return 'moyasar';
    }

    public function publishableKey(): ?string
    {
        return $this->publishable;
    }

    public function capture(Order $order, float $amount, string $channel, ?string $paymentRef): string
    {
        if ($paymentRef === null || $paymentRef === '') {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payment_not_confirmed'));
        }

        // A read-only GET, run outside the payment transaction so a slow gateway never
        // holds a row lock and a database connection under it.
        $response = Http::withBasicAuth($this->secret, '')
            ->timeout(self::READ_TIMEOUT)
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->acceptJson()
            ->get(rtrim($this->baseUrl, '/')."/payments/{$paymentRef}");

        if ($response->failed()) {
            abort(Response::HTTP_BAD_GATEWAY, __('api.payment_gateway_unreachable'));
        }

        $body = $response->json();

        $paid = ($body['status'] ?? null) === 'paid';
        $amountMatches = (int) round($amount * 100) === (int) ($body['amount'] ?? 0);
        $currencyMatches = strtoupper((string) ($body['currency'] ?? '')) === 'SAR';

        if (! $paid || ! $amountMatches || ! $currencyMatches) {
            abort(Response::HTTP_PAYMENT_REQUIRED, __('api.payment_not_confirmed'));
        }

        // Conditional order binding: reject a conflicting id, tolerate a missing one.
        $metadataOrderId = $body['metadata']['order_id'] ?? $body['metadata']['orderId'] ?? null;
        if ($metadataOrderId !== null && (int) $metadataOrderId !== $order->getKey()) {
            abort(Response::HTTP_PAYMENT_REQUIRED, __('api.payment_not_for_this_order'));
        }

        return (string) ($body['id'] ?? $paymentRef);
    }
}
