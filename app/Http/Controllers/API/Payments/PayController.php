<?php

namespace App\Http\Controllers\API\Payments;

use App\Http\Controllers\API\BaseController;
use App\Models\Order;
use App\Services\Payments\OnlinePaymentService;
use App\Services\Payments\PaymentWebhookService;
use App\Services\Payments\PayTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public payment surfaces: the hosted pay page (load + execute) reached only with a
 * signed token, and the Moyasar webhook. The amount is always recomputed server-side.
 */
class PayController extends BaseController
{
    public function __construct(
        private readonly PayTokenService $tokens,
        private readonly OnlinePaymentService $payments,
        private readonly PaymentWebhookService $webhook,
    ) {
        parent::__construct();
    }

    /**
     * Load the payment page for a token.
     */
    public function show(string $token): JsonResponse
    {
        $order = $this->resolveOrder($token);

        return successResponse($this->payments->linkSummary($order));
    }

    /**
     * Execute a gateway payment for a token.
     */
    public function pay(Request $request, string $token): JsonResponse
    {
        $order = $this->resolveOrder($token);

        $data = $request->validate([
            'channel' => ['nullable', 'in:mada,card,stcpay,applepay'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'payment_ref' => ['nullable', 'string', 'max:255'],
        ]);

        $result = $this->payments->pay(
            $order,
            $data['channel'] ?? 'card',
            isset($data['amount']) ? (float) $data['amount'] : null,
            $data['payment_ref'] ?? null,
        );

        return successResponse($result);
    }

    /**
     * The Moyasar webhook. Always answers 200 for anything processed or ignored.
     */
    public function webhook(Request $request): JsonResponse
    {
        $this->webhook->handle($request->all());

        return successResponse(['received' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function resolveOrder(string $token): Order
    {
        $inspection = $this->tokens->inspect($token, time());

        abort_if($inspection['state'] === 'invalid', 404, __('api.payment_link_invalid'));
        abort_if($inspection['state'] === 'expired', 410, __('api.payment_link_expired'));

        $order = Order::query()->find($inspection['order_id']);
        abort_if($order === null, 404, __('api.order_not_found'));

        return $order;
    }
}
