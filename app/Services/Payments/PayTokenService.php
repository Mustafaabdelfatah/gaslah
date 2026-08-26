<?php

namespace App\Services\Payments;

use Illuminate\Support\Facades\Config;

/**
 * A stateless, signed capability to pay a single order.
 *
 * The token carries only the order id and an expiry; the amount is always recomputed
 * server-side. The `~` separator is unreserved in RFC 3986 and absent from base64url, so
 * the token drops cleanly into a `/pay/{token}` path. Comparison is timing-safe.
 */
class PayTokenService
{
    public const DEFAULT_TTL_SECONDS = 72 * 3600;

    /**
     * Mint a token for an order.
     */
    public function mint(int $orderId, int $nowTimestamp, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $payload = $this->encode(['o' => $orderId, 'e' => $nowTimestamp + $ttlSeconds]);

        return $payload.'~'.$this->sign($payload);
    }

    /**
     * The order id for a valid, unexpired token, or null.
     */
    public function verify(string $token, int $nowTimestamp): ?int
    {
        $claims = $this->claims($token);

        if ($claims === null || $claims['e'] <= $nowTimestamp) {
            return null;
        }

        return $claims['o'];
    }

    /**
     * Distinguish a valid, expired, or invalid token (for 200 / 410 / 404).
     *
     * @return array{state: 'valid'|'expired'|'invalid', order_id?: int}
     */
    public function inspect(string $token, int $nowTimestamp): array
    {
        $claims = $this->claims($token);

        if ($claims === null) {
            return ['state' => 'invalid'];
        }

        if ($claims['e'] <= $nowTimestamp) {
            return ['state' => 'expired'];
        }

        return ['state' => 'valid', 'order_id' => $claims['o']];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array{o: int, e: int}|null
     */
    private function claims(string $token): ?array
    {
        if (! str_contains($token, '~')) {
            return null;
        }

        [$payload, $signature] = explode('~', $token, 2);

        if (! hash_equals($this->sign($payload), $signature)) {
            return null;
        }

        $decoded = json_decode($this->base64UrlDecode($payload), true);

        if (! is_array($decoded) || ! isset($decoded['o'], $decoded['e'])) {
            return null;
        }

        return ['o' => (int) $decoded['o'], 'e' => (int) $decoded['e']];
    }

    /**
     * @param  array<string, int>  $claims
     */
    private function encode(array $claims): string
    {
        return $this->base64UrlEncode(json_encode($claims));
    }

    private function sign(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', 'pay:'.$payload, $this->secret(), true));
    }

    private function secret(): string
    {
        $key = (string) Config::get('app.key');

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7)) ?: $key;
        }

        return $key;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
