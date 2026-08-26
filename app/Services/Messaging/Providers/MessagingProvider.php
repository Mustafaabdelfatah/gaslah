<?php

namespace App\Services\Messaging\Providers;

/**
 * A messaging transport. send() never throws on an ordinary delivery failure; canDeliver()
 * answers whether this provider actually reaches a real person (OTP flows fail closed when
 * it does not).
 */
interface MessagingProvider
{
    /**
     * @return array{status: string, provider: string, id?: string}
     */
    public function send(string $to, string $body, string $channel): array;

    public function canDeliver(): bool;
}
