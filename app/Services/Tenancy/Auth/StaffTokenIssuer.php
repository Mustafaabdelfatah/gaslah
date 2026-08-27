<?php

namespace App\Services\Tenancy\Auth;

use App\Enum\Global\TokenKindEnum;
use App\Models\User;

/**
 * Mints staff session tokens.
 *
 * Every staff token — whether it comes from signing in or from provisioning a brand new
 * tenant — is stamped here, so the kind and the tenant context a token carries are
 * described in exactly one place.
 */
class StaffTokenIssuer
{
    /**
     * @param  array<string, mixed>  $meta  caller-supplied device hints
     */
    public function issue(User $user, int $organizationId, int $branchId, array $meta = []): string
    {
        $token = $user->createToken(TokenKindEnum::Staff->value);

        $token->accessToken->forceFill([
            'meta' => [
                'kind' => TokenKindEnum::Staff->value,
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'device' => $this->deviceMeta($meta),
            ],
        ])->save();

        return $token->plainTextToken;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function deviceMeta(array $meta): array
    {
        return [
            'platform' => $meta['platform'] ?? null,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ];
    }
}
