<?php

namespace App\Enum\Support;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Which side of the conversation wrote a message.
 *
 * Stored rather than inferred from the author: a platform admin who later becomes a
 * tenant's own staff member must not retroactively change who was speaking.
 */
enum SupportAuthorTypeEnum: string
{
    use EnumMethods;

    case Tenant = 'tenant';
    case Admin = 'admin';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
