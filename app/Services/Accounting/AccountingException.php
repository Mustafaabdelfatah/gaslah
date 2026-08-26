<?php

namespace App\Services\Accounting;

use RuntimeException;

/**
 * Raised when a journal entry cannot be posted: it does not balance, has too few
 * lines, or otherwise violates the double-entry invariants.
 */
class AccountingException extends RuntimeException
{
    public static function unbalanced(int $debitHalalas, int $creditHalalas): self
    {
        return new self(sprintf('UNBALANCED_ENTRY: debit=%d credit=%d halalas', $debitHalalas, $creditHalalas));
    }

    public static function needsTwoLines(): self
    {
        return new self('ENTRY_NEEDS_TWO_LINES');
    }
}
