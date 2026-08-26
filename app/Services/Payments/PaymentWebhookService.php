<?php

namespace App\Services\Payments;

use App\Enum\Payments\OnlineChargeStatusEnum;
use App\Models\OnlineCharge;
use App\Models\Order;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Moyasar webhook — the only settlement path independent of the customer's browser
 * surviving the redirect.
 *
 * Fail-closed: no configured secret is a 503, a wrong secret is a 401 (so the
 * misconfiguration is seen). Everything it processes or deliberately ignores returns
 * 200, because Moyasar retries any non-2xx forever. A paid event is re-fetched
 * server-side (never trusting the body beyond the id) and settled once through the shared
 * `gateway:{txnId}` key.
 */
class PaymentWebhookService
{
    public function __construct(private readonly OnlinePaymentService $payments) {}

    /**
     * Process a webhook body. Aborts 503/401 on configuration/auth failure; otherwise
     * returns (the controller always answers 200).
     *
     * @param  array<string, mixed>  $body
     */
    public function handle(array $body): void
    {
        $secret = config('services.moyasar.webhook_secret');

        // No secret: anyone could post "paid" and settle any order for free.
        abort_if(empty($secret), Response::HTTP_SERVICE_UNAVAILABLE, __('api.webhook_not_configured'));

        $provided = (string) ($body['secret_token'] ?? '');
        abort_unless(hash_equals((string) $secret, $provided), Response::HTTP_UNAUTHORIZED, __('api.webhook_bad_secret'));

        $type = $body['type'] ?? null;
        $txnId = $body['data']['id'] ?? null;

        if ($txnId === null) {
            return;
        }

        if ($type === 'payment_refunded') {
            OnlineCharge::query()->where('provider_ref', $txnId)->update(['status' => OnlineChargeStatusEnum::Refunded->value, 'raw_status' => 'refunded']);

            return;
        }

        if (! in_array($type, ['payment_paid', 'payment_captured'], true)) {
            return;
        }

        $charge = $this->fetchCharge((string) $txnId);

        if (($charge['status'] ?? null) !== 'paid') {
            return;
        }

        // The webhook requires order_id (unlike capture, which tolerates a missing one).
        $orderId = $charge['metadata']['order_id'] ?? $charge['metadata']['orderId'] ?? null;
        if ($orderId === null) {
            return;
        }

        $order = Order::query()->find((int) $orderId);
        if ($order === null) {
            return;
        }

        $amount = round(((int) ($charge['amount'] ?? 0)) / 100, 2);
        $this->payments->settleFromWebhook($order, (string) $txnId, $amount, 'moyasar');
    }

    /**
     * Re-fetch the charge server-side with the secret key.
     *
     * @return array<string, mixed>
     */
    private function fetchCharge(string $txnId): array
    {
        $response = Http::withBasicAuth((string) config('services.moyasar.secret'), '')
            ->acceptJson()
            ->get(rtrim((string) config('services.moyasar.base_url'), '/')."/payments/{$txnId}");

        return $response->ok() ? (array) $response->json() : [];
    }
}
