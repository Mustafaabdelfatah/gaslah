<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Log;

/**
 * The default stub provider used when no real credentials are configured.
 *
 * It never logs the body (which may carry a live OTP — leaking it would turn log access
 * into full account takeover); only a masked number, the channel, and the body length.
 */
class LogProvider implements MessagingProvider
{
    public function send(string $to, string $body, string $channel): array
    {
        Log::info('messaging.logged', [
            'to' => $this->mask($to),
            'channel' => $channel,
            'length' => mb_strlen($body),
        ]);

        return ['status' => 'logged', 'provider' => 'log'];
    }

    public function canDeliver(): bool
    {
        return false;
    }

    private function mask(string $phone): string
    {
        return mb_strlen($phone) <= 4 ? '****' : '****'.mb_substr($phone, -3);
    }
}
