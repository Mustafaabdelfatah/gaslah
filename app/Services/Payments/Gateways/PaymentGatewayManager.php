<?php

namespace App\Services\Payments\Gateways;

/**
 * Resolves the active payment gateway, fail-closed.
 *
 * The stub is available only in local/testing; production without full credentials
 * resolves to the Unavailable gateway (503) rather than pretending a charge succeeded.
 */
class PaymentGatewayManager
{
    public function resolve(): PaymentGateway
    {
        $driver = config('services.payment.driver');

        if ($driver === null) {
            $driver = $this->isLocal() ? 'stub' : 'moyasar';
        }

        return match ($driver) {
            'stub' => $this->isLocal()
                ? new StubGateway
                : new UnavailableGateway(__('api.payment_stub_not_allowed')),
            'moyasar' => $this->moyasar(),
            default => new UnavailableGateway(__('api.payment_gateway_not_configured')),
        };
    }

    private function moyasar(): PaymentGateway
    {
        $secret = config('services.moyasar.secret');

        if (empty($secret)) {
            return new UnavailableGateway(__('api.payment_gateway_not_configured'));
        }

        return new MoyasarGateway(
            $secret,
            config('services.moyasar.publishable'),
            config('services.moyasar.base_url'),
        );
    }

    private function isLocal(): bool
    {
        return app()->environment('local', 'testing');
    }
}
