<?php

namespace App\Guards;

use App\Models\User;
use Laravel\Sanctum\Guard as BaseSanctumGuard;
use Laravel\Sanctum\Sanctum;

class SanctumGuard extends BaseSanctumGuard
{
    /**
     * Determine if the provided access token is valid.
     *
     * A token is a snapshot of who the caller was when they signed in, so the
     * database is consulted again on every request: a disabled account or one whose
     * organization membership was withdrawn loses access immediately instead of at
     * the token's natural expiry.
     *
     * @param  mixed  $accessToken
     */
    protected function isValidAccessToken($accessToken): bool
    {
        if (! $accessToken) {
            return false;
        }

        // Idle timeout: measured from last use rather than issuance, so an active
        // session is not cut off mid-shift.
        $lastUsedAt = $accessToken->last_used_at ?? $accessToken->created_at;

        $isValid = (! $this->expiration || $lastUsedAt->gt(now()->subMinutes($this->expiration)))
            && (! $accessToken->expires_at || ! $accessToken->expires_at->isPast())
            && $this->hasValidProvider($accessToken->tokenable)
            && $this->passesLiveVerification($accessToken);

        if (is_callable(Sanctum::$accessTokenAuthenticationCallback)) {
            $isValid = (bool) (Sanctum::$accessTokenAuthenticationCallback)($accessToken, $isValid);
        }

        return $isValid;
    }

    /**
     * Re-check the live state behind the token.
     *
     * @param  mixed  $accessToken
     */
    protected function passesLiveVerification($accessToken): bool
    {
        $tokenable = $accessToken->tokenable;

        if (! $tokenable instanceof User) {
            return true;
        }

        if (! $tokenable->is_active) {
            return false;
        }

        $organizationId = data_get($accessToken->meta, 'organization_id');

        // Platform administrators sign in without an organization; nothing further
        // to verify for them.
        if ($organizationId === null) {
            return true;
        }

        return $tokenable->branches()
            ->where('branches.organization_id', $organizationId)
            ->exists();
    }
}
