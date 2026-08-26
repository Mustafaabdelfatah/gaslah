<?php

namespace App\Services\Tenancy\Auth;

use App\Enum\Global\TokenKindEnum;
use App\Enum\Tenancy\SecuritySurfaceEnum;
use App\Models\User;
use App\Services\Auth\SecurityLogService;
use App\Services\Tenancy\StaffPermissionService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a staff member into the tenant application.
 *
 * The token carries the organization and the branch resolved at sign-in as metadata,
 * which is what lets the guard re-check membership on every later request and pins
 * where the caller's writes land.
 */
class StaffAuthService
{
    public function __construct(
        private readonly SecurityLogService $securityLog,
        private readonly StaffPermissionService $permissions,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{email: string, password: string, meta?: array<string, mixed>}  $data
     * @return array{user: User, token: string, organization_id: int, branch_id: int, permissions: array<int, string>}
     */
    public function login(array $data): array
    {
        $surface = SecuritySurfaceEnum::Staff;
        $email = $data['email'];

        // Refuse before checking the password, so a locked address cannot be probed.
        $this->securityLog->ensureNotLocked($surface, $email);

        $user = $this->resolveVerifiedUser($email, $data['password'] ?? '', $surface);

        [$organizationId, $branchId] = $this->resolveWriteScope($user, $surface);

        $this->securityLog->recordSuccess($surface, $user, $email);
        $user->forceFill(['last_login' => now()])->saveQuietly();

        $token = $this->issueToken($user, $organizationId, $branchId, $data['meta'] ?? []);

        // Bind the context to this freshly signed-in user so the response can read
        // its effective permissions without a second resolution pass.
        $this->tenant->forUser($user);

        return [
            'user' => $user,
            'token' => $token,
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'permissions' => $this->permissions->effectiveFor($user),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Verify credentials, recording the failure with a uniform message.
     *
     * The same message covers an unknown address and a wrong password so the
     * response cannot be used to enumerate accounts.
     */
    private function resolveVerifiedUser(string $email, string $password, SecuritySurfaceEnum $surface): User
    {
        $user = User::query()->where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            $this->securityLog->recordFailure($surface, $email, 'bad_credentials', $user);
            $this->fail(__('api.invalid_email_and_password'), Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->is_active) {
            $this->securityLog->recordFailure($surface, $email, 'not_active', $user);
            $this->fail(__('api.account_not_active'), Response::HTTP_UNAUTHORIZED);
        }

        return $user;
    }

    /**
     * Resolve the organization and write branch, refusing an account tied to none.
     *
     * @return array{0: int, 1: int}
     */
    private function resolveWriteScope(User $user, SecuritySurfaceEnum $surface): array
    {
        $membership = $user->userBranches()
            ->with('branch')
            ->get()
            ->first(fn ($userBranch) => $userBranch->branch !== null);

        if ($membership === null) {
            // A platform administrator signs in through their own surface, not here.
            $this->securityLog->recordFailure($surface, $user->email, 'no_organization', $user);
            $this->fail(__('api.account_not_linked_to_organization'), Response::HTTP_FORBIDDEN);
        }

        return [$membership->branch->organization_id, $membership->branch_id];
    }

    private function issueToken(User $user, int $organizationId, int $branchId, array $meta): string
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

    private function fail(string $message, int $status): never
    {
        abort($status, $message);
    }
}
