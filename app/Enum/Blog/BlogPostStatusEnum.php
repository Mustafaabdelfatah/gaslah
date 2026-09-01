<?php

namespace App\Enum\Blog;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Where an article stands. Draft is invisible to readers, Published is live once its
 * publish date has passed, and Archived is taken down without being destroyed.
 */
enum BlogPostStatusEnum: string
{
    use EnumMethods;

    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
