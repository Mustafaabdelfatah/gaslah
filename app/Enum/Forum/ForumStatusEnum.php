<?php

namespace App\Enum\Forum;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Moderation status of a forum thread or post. A new thread starts Pending; replies are
 * post-moderated (Approved on creation).
 */
enum ForumStatusEnum: string
{
    use EnumMethods;

    case Pending = 'pending';
    case Approved = 'approved';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
