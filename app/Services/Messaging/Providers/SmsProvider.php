<?php

namespace App\Services\Messaging\Providers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsProvider implements MessagingProvider
{
    public function __construct(
        private readonly ?string $token,
        private readonly ?string $sender,
        private readonly ?string $url,
    ) {}

    public function send(string $to, string $body, string $channel): array
    {
        if ($channel !== 'sms' || ! $this->canDeliver()) {
            return ['status' => 'failed', 'provider' => 'sms'];
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->connectTimeout(5)
                ->post($this->url, [
                    'sender' => $this->sender,
                    'recipients' => [$to],
                    'body' => $body,
                ]);

            if ($response->successful()) {
                return [
                    'status' => 'sent',
                    'provider' => 'sms',
                    'id' => (string) ($response->json('id') ?? $response->json('message_id') ?? ''),
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('messaging.sms.failed', ['to' => $this->mask($to), 'error' => $exception->getMessage()]);

            return ['status' => 'failed', 'provider' => 'sms'];
        }

        Log::warning('messaging.sms.failed', ['to' => $this->mask($to)]);

        return ['status' => 'failed', 'provider' => 'sms'];
    }

    public function canDeliver(): bool
    {
        return filled($this->url) && filled($this->token) && filled($this->sender);
    }

    private function mask(string $phone): string
    {
        return mb_strlen($phone) <= 4 ? '****' : '****'.mb_substr($phone, -3);
    }
}
