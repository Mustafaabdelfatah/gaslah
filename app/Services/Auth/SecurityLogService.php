<?php

namespace App\Services\Auth;

use App\Enum\Tenancy\SecurityActionEnum;
use App\Enum\Tenancy\SecuritySurfaceEnum;
use App\Models\SecurityLog;
use App\Models\User;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records sign-in attempts and derives the lockout window from them.
 *
 * Counting is scoped to the address *and* the caller's IP. Counting by address alone
 * would let anyone keep a known account locked out from a single machine — including
 * the sole platform owner, who has no self-service way back in.
 */
class SecurityLogService
{
    public function recordSuccess(SecuritySurfaceEnum $surface, User $user, ?string $email = null): void
    {
        $this->write($surface, SecurityActionEnum::LoginOk, $email ?? $user->email, null, $user);
    }

    /**
     * Record a rejected attempt, including one that matched no account.
     */
    public function recordFailure(
        SecuritySurfaceEnum $surface,
        ?string $email,
        string $reason,
        ?User $user = null
    ): void {
        $this->write($surface, SecurityActionEnum::LoginFailed, $email, $reason, $user);
    }

    /**
     * Seconds remaining on the lockout, or zero when the caller may try again.
     */
    public function lockedForSeconds(SecuritySurfaceEnum $surface, ?string $email): int
    {
        $config = $this->config();

        if (! $config['enabled']) {
            return 0;
        }

        $query = SecurityLog::query()->forLockout($email, $this->ip(), $surface);

        // A successful sign-in wipes the slate: someone who fumbles their password
        // and then gets it right is not one attempt away from being locked out.
        $lastSuccessAt = (clone $query)
            ->where('action', SecurityActionEnum::LoginOk->value)
            ->max('created_at');

        $failures = (clone $query)
            ->where('action', SecurityActionEnum::LoginFailed->value)
            ->where('created_at', '>=', now()->subMinutes($config['window_minutes']))
            ->when($lastSuccessAt !== null, fn ($builder) => $builder->where('created_at', '>', $lastSuccessAt))
            ->orderByDesc('created_at')
            ->get(['created_at']);

        if ($failures->count() < $config['max_attempts']) {
            return 0;
        }

        $unlockAt = $failures->first()->created_at->addMinutes($config['lockout_minutes']);

        if ($unlockAt->isPast()) {
            return 0;
        }

        return max(1, (int) ceil(now()->diffInSeconds($unlockAt, absolute: true)));
    }

    /**
     * Refuse the attempt while the caller is locked out.
     */
    public function ensureNotLocked(SecuritySurfaceEnum $surface, ?string $email): void
    {
        $seconds = $this->lockedForSeconds($surface, $email);

        if ($seconds > 0) {
            abort(
                Response::HTTP_TOO_MANY_REQUESTS,
                __('api.login_locked_try_later', ['seconds' => $seconds]),
                ['Retry-After' => $seconds]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function write(
        SecuritySurfaceEnum $surface,
        SecurityActionEnum $action,
        ?string $email,
        ?string $reason,
        ?User $user
    ): void {
        SecurityLog::query()->create([
            'user_id' => $user?->getKey(),
            'email' => $email,
            'surface' => $surface->value,
            'ip_address' => $this->ip(),
            'action' => $action->value,
            'reason' => $reason,
            'user_agent' => mb_substr((string) request()->userAgent(), 0, 255) ?: null,
        ]);
    }

    private function ip(): ?string
    {
        return request()->ip();
    }

    /**
     * @return array{enabled: bool, max_attempts: int, window_minutes: int, lockout_minutes: int}
     */
    private function config(): array
    {
        $config = config('project.auth.lockout', []);

        return [
            'enabled' => (bool) ($config['enabled'] ?? true),
            'max_attempts' => (int) ($config['max_attempts'] ?? 10),
            'window_minutes' => (int) ($config['window_minutes'] ?? 15),
            'lockout_minutes' => (int) ($config['lockout_minutes'] ?? 15),
        ];
    }
}
