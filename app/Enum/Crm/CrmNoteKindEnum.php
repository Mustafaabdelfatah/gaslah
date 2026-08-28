<?php

namespace App\Enum\Crm;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * What a CRM entry records: something that happened, or something to do.
 */
enum CrmNoteKindEnum: string
{
    use EnumMethods;

    case Note = 'note';
    case Call = 'call';
    case Email = 'email';
    case Meeting = 'meeting';
    case Task = 'task';

    /**
     * Only a task can be completed. Marking a record of a phone call "done" is
     * meaningless — it already happened.
     */
    public function isCompletable(): bool
    {
        return $this === self::Task;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
