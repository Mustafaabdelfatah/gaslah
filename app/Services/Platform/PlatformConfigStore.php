<?php

namespace App\Services\Platform;

use App\Models\PlatformConfig;
use Illuminate\Support\Carbon;

/**
 * Typed accessor over the platform key/value config store. Values are JSON, so a scalar
 * is wrapped and unwrapped to keep callers working with plain PHP types.
 */
class PlatformConfigStore
{
    private const BOOKS_KEY = 'platformBooks';

    public function get(string $key, mixed $default = null): mixed
    {
        $row = PlatformConfig::query()->find($key);

        return $row === null ? $default : $row->value;
    }

    public function put(string $key, mixed $value): void
    {
        PlatformConfig::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => Carbon::now()],
        );
    }

    /**
     * The id of the reserved organization holding the platform's own books, or null if
     * not yet provisioned.
     */
    public function platformBooksOrgId(): ?int
    {
        $value = $this->get(self::BOOKS_KEY);

        $orgId = is_array($value) ? ($value['orgId'] ?? null) : null;

        return $orgId === null ? null : (int) $orgId;
    }

    public function setPlatformBooksOrgId(int $orgId): void
    {
        $this->put(self::BOOKS_KEY, ['orgId' => $orgId]);
    }
}
