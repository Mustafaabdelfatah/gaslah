<?php

namespace App\Services\Delivery;

use Illuminate\Support\Facades\Config;

/**
 * A stateless, domain-separated HMAC token that names a single branch for the in-store
 * display screen.
 *
 * It carries no expiry — the link lives on a TV, and rotating the signing key is what
 * invalidates every display link at once. The `display:` prefix keeps a display token
 * from ever being mistaken for another surface's token, and the `~` separator avoids
 * clashing with a dotted route segment.
 */
class DisplayTokenService
{
    /**
     * Mint a display token for a branch.
     */
    public function mint(int $branchId): string
    {
        return $branchId.'~'.$this->sign($branchId);
    }

    /**
     * Resolve the branch id from a token, or null when it is malformed or forged.
     */
    public function verify(?string $token): ?int
    {
        if ($token === null || ! str_contains($token, '~')) {
            return null;
        }

        [$branchId, $signature] = explode('~', $token, 2);

        if (! ctype_digit($branchId)) {
            return null;
        }

        $expected = $this->sign((int) $branchId);

        return hash_equals($expected, $signature) ? (int) $branchId : null;
    }

    private function sign(int $branchId): string
    {
        return hash_hmac('sha256', 'display:'.$branchId, $this->secret());
    }

    private function secret(): string
    {
        $key = (string) Config::get('app.key');

        // Support base64-encoded application keys.
        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7)) ?: $key;
        }

        return $key;
    }
}
