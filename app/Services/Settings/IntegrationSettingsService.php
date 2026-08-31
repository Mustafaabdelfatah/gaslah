<?php

namespace App\Services\Settings;

use App\Enum\Payments\PaymentMethodEnum;
use App\Models\OrganizationIntegration;

/**
 * A tenant's integration settings, and the two rules that keep their credentials safe.
 *
 * Reads never return a secret — only whether one is set. Writes never erase one by
 * omission: a blank field means "leave it as it is", because a form that renders secrets
 * as empty would otherwise wipe them the first time somebody saved an unrelated change.
 */
class IntegrationSettingsService
{
    /**
     * Message events a tenant may switch. Anything else sent is dropped rather than
     * stored, so an unknown key cannot sit in the config pretending to gate something.
     */
    private const EVENTS = [
        'orderCreated', 'orderReady', 'orderCompleted', 'otp',
        'invoice', 'delivery', 'manual', 'test',
    ];

    private const TEMPLATES = ['orderReady', 'otp', 'paymentLink', 'winBack'];

    public function forOrganization(int $organizationId): OrganizationIntegration
    {
        $config = OrganizationIntegration::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'payment_methods' => $this->defaultPaymentMethods(),
                'events' => $this->defaultEvents(),
                'templates' => [],
            ],
        );

        // A freshly created model carries only what was passed in, so the columns whose
        // defaults live in the schema would read as null until it is re-read.
        return $config->wasRecentlyCreated ? $config->refresh() : $config;
    }

    /**
     * The settings as a client may see them: every secret blanked, with a flag saying
     * whether one is stored.
     *
     * @return array<string, mixed>
     */
    public function present(int $organizationId): array
    {
        $config = $this->forOrganization($organizationId);

        return [
            'payment' => [
                'methods' => $config->payment_methods ?? $this->defaultPaymentMethods(),
                'gateway' => [
                    'provider' => $config->gateway_provider,
                    'public_key' => $config->gateway_public_key ?? '',
                    'secret_key' => '',
                ],
            ],
            'messaging' => [
                'enabled' => $config->messaging_enabled,
                'whatsapp' => [
                    'enabled' => $config->whatsapp_enabled,
                    'mode' => $config->whatsapp_mode,
                    'token' => '',
                    'phone_id' => $config->whatsapp_phone_id ?? '',
                ],
                'sms' => [
                    'enabled' => $config->sms_enabled,
                    'provider' => $config->sms_provider ?? '',
                    'api_key' => '',
                    'sender' => $config->sms_sender ?? '',
                ],
                'events' => $config->events ?? $this->defaultEvents(),
                'templates' => $config->templates ?? [],
            ],
            // All the UI needs to render "configured" without ever holding the value.
            'secrets_set' => [
                'gateway_secret' => filled($config->gateway_secret_key),
                'whatsapp_token' => filled($config->whatsapp_token),
                'sms_api_key' => filled($config->sms_api_key),
            ],
        ];
    }

    /**
     * Apply an update and return the same safe view.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function update(int $organizationId, array $input): array
    {
        $config = $this->forOrganization($organizationId);
        $payment = $input['payment'] ?? [];
        $messaging = $input['messaging'] ?? [];

        $config->fill(array_filter([
            'payment_methods' => $this->cleanMethods($payment['methods'] ?? null),
            'gateway_provider' => data_get($payment, 'gateway.provider'),
            'gateway_public_key' => data_get($payment, 'gateway.public_key'),

            'messaging_enabled' => $messaging['enabled'] ?? null,
            'whatsapp_enabled' => data_get($messaging, 'whatsapp.enabled'),
            'whatsapp_mode' => data_get($messaging, 'whatsapp.mode'),
            'whatsapp_phone_id' => data_get($messaging, 'whatsapp.phone_id'),

            'sms_enabled' => data_get($messaging, 'sms.enabled'),
            'sms_provider' => data_get($messaging, 'sms.provider'),
            'sms_sender' => data_get($messaging, 'sms.sender'),

            'events' => $this->cleanEvents($messaging['events'] ?? null, $config->events ?? $this->defaultEvents()),
            'templates' => $this->cleanTemplates($messaging['templates'] ?? null, $config->templates ?? []),
        ], static fn ($value) => $value !== null));

        $this->applySecret($config, 'gateway_secret_key', data_get($payment, 'gateway.secret_key'));
        $this->applySecret($config, 'whatsapp_token', data_get($messaging, 'whatsapp.token'));
        $this->applySecret($config, 'sms_api_key', data_get($messaging, 'sms.api_key'));

        $config->save();

        return $this->present($organizationId);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * A blank secret means "keep what is stored".
     *
     * The read side blanks secrets, so a form round-trip always sends them empty. Treating
     * that as "clear it" would silently disconnect a tenant's gateway the first time they
     * changed anything else on the page.
     */
    private function applySecret(OrganizationIntegration $config, string $column, mixed $value): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $config->{$column} = trim($value);
    }

    /**
     * @return array<string, bool>|null
     */
    private function cleanMethods(mixed $methods): ?array
    {
        if (! is_array($methods)) {
            return null;
        }

        $clean = [];

        foreach (PaymentMethodEnum::values() as $method) {
            $clean[$method] = (bool) ($methods[$method] ?? false);
        }

        return $clean;
    }

    /**
     * Merge the supplied switches onto the stored ones.
     *
     * A partial map changes only the events it names. Replacing the map outright would
     * mean a caller who sends one switch silently turns every other one off — the same
     * erasure-by-omission this class refuses for secrets.
     *
     * @param  array<string, bool>  $stored
     * @return array<string, bool>|null
     */
    private function cleanEvents(mixed $events, array $stored): ?array
    {
        if (! is_array($events)) {
            return null;
        }

        $clean = $stored;

        foreach (self::EVENTS as $event) {
            if (array_key_exists($event, $events)) {
                $clean[$event] = (bool) $events[$event];
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $stored
     * @return array<string, string>|null
     */
    private function cleanTemplates(mixed $templates, array $stored): ?array
    {
        if (! is_array($templates)) {
            return null;
        }

        $clean = $stored;

        foreach (self::TEMPLATES as $key) {
            if (array_key_exists($key, $templates)) {
                $clean[$key] = (string) $templates[$key];
            }
        }

        return $clean;
    }

    /**
     * @return array<string, bool>
     */
    private function defaultPaymentMethods(): array
    {
        return array_fill_keys(PaymentMethodEnum::values(), true);
    }

    /**
     * @return array<string, bool>
     */
    private function defaultEvents(): array
    {
        return [
            'orderCreated' => false,
            'orderReady' => true,
            'orderCompleted' => false,
            'otp' => true,
            'invoice' => true,
            'delivery' => true,
            'manual' => false,
            'test' => false,
        ];
    }
}
