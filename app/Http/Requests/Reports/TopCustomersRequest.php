<?php

namespace App\Http\Requests\Reports;

/**
 * The top-customers leaderboard: a reporting window plus how many names to return.
 */
class TopCustomersRequest extends DateRangeRequest
{
    private const DEFAULT_LIMIT = 10;

    public function rules(): array
    {
        return [
            ...parent::rules(),
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function limit(): int
    {
        return $this->filled('limit') ? $this->integer('limit') : self::DEFAULT_LIMIT;
    }
}
