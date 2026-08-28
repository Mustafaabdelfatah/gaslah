<?php

namespace App\Enum\Support;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Where a support ticket stands.
 *
 * Open and pending both mean live; they differ in who is holding the ball. Pending is
 * "we answered, the tenant has not" — which is what keeps an answered ticket out of the
 * queue without closing it.
 */
enum SupportTicketStatusEnum: string
{
    use EnumMethods;

    case Open = 'open';
    case Pending = 'pending';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * A ticket the tenant replies to comes back to life, whatever the operator had marked
     * it. Someone still needs help.
     */
    public function isSettled(): bool
    {
        return in_array($this, [self::Resolved, self::Closed], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
