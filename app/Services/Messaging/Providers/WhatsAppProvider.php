<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The WhatsApp Cloud API provider. Timeouts are tight so an order/OTP path is never hung.
 * On failure it logs a warning with the number masked and never the body (no PII/OTP in
 * logs), and returns FAILED rather than throwing.
 */
class WhatsAppProvider implements MessagingProvider
{
    public function __construct(
        private readonly string $token,
        private readonly string $phoneId,
        private readonly string $baseUrl = 'https://graph.facebook.com/v19.0',
    ) {}

    public function send(string $to, string $body, string $channel): array
    {
        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->connectTimeout(5)
                ->post(rtrim($this->baseUrl, '/')."/{$this->phoneId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $body],
                ]);

            if ($response->successful()) {
                return ['status' => 'sent', 'provider' => 'whatsapp', 'id' => $response->json('messages.0.id', '')];
            }
        } catch (\Throwable $exception) {
            Log::warning('messaging.whatsapp.failed', ['to' => $this->mask($to), 'error' => $exception->getMessage()]);

            return ['status' => 'failed', 'provider' => 'whatsapp'];
        }

        Log::warning('messaging.whatsapp.failed', ['to' => $this->mask($to)]);

        return ['status' => 'failed', 'provider' => 'whatsapp'];
    }

    public function canDeliver(): bool
    {
        return true;
    }

    private function mask(string $phone): string
    {
        return mb_strlen($phone) <= 4 ? '****' : '****'.mb_substr($phone, -3);
    }
}
