<?php

namespace App\Services\Tenancy\Auth;

use App\Enum\Global\TokenKindEnum;
use App\Enum\Tenancy\SecuritySurfaceEnum;
use App\Models\User;
use App\Services\Auth\SecurityLogService;
use App\Services\Tenancy\PlatformAccessService;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a Gaslah operator into the platform console.
 *
 * A separate surface from staff sign-in: valid tenant credentials are refused here
 * unless the account also carries the platform-owner flag, so an ordinary staff
 * member cannot reach the operator console however correct their password.
 */
class PlatformAuthService
{
    public function __construct(
        private readonly SecurityLogService $securityLog,
        private readonly PlatformAccessService $access,
    ) {}

    /**
     * @param  array{email: string, password: string, meta?: array<string, mixed>}  $data
     * @return array{user: User, token: string, permissions: array<int, string>}
     */
    public function login(array $data): array
    {
        $surface = SecuritySurfaceEnum::Admin;
        $email = $data['email'];

        $this->securityLog->ensureNotLocked($surface, $email);

        $user = $this->resolveVerifiedUser($email, $data['password'] ?? '', $surface);

        $this->securityLog->recordSuccess($surface, $user, $email);
        $user->forceFill(['last_login' => now()])->saveQuietly();

        return [
            'user' => $user,
            'token' => $this->issueToken($user),
            'permissions' => $this->access->permissions($user),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
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

        // The extra gate that keeps a tenant account out of the operator console.
        if (! $user->isPlatformAdmin()) {
            $this->securityLog->recordFailure($surface, $email, 'not_owner', $user);
            $this->fail(__('api.not_a_platform_admin'), Response::HTTP_FORBIDDEN);
        }

        return $user;
    }

    private function issueToken(User $user): string
    {
        // No organization or branch: a platform token has no tenant scope of its own.
        $token = $user->createToken(TokenKindEnum::Platform->value);

        $token->accessToken->forceFill([
            'meta' => [
                'kind' => TokenKindEnum::Platform->value,
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ],
        ])->save();

        return $token->plainTextToken;
    }

    private function fail(string $message, int $status): never
    {
        abort($status, $message);
    }
}
