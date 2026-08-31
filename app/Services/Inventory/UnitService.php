<?php

namespace App\Services\Inventory;

use App\Models\Unit;
use Illuminate\Database\Eloquent\Collection;

/**
 * Units of measure for the stock room.
 *
 * They are not user-managed — an item simply has to be counted in something —
 * so a tenant gets the usual set the first time its shelf is opened, the same
 * way the chart of accounts appears on the first accounting read.
 */
class UnitService
{
    /**
     * @var array<int, array{name: string, symbol: string}>
     */
    private const DEFAULTS = [
        ['name' => 'قطعة', 'symbol' => 'قطعة'],
        ['name' => 'كيلوغرام', 'symbol' => 'كجم'],
        ['name' => 'غرام', 'symbol' => 'غم'],
        ['name' => 'لتر', 'symbol' => 'لتر'],
        ['name' => 'مليلتر', 'symbol' => 'مل'],
        ['name' => 'علبة', 'symbol' => 'علبة'],
        ['name' => 'كرتون', 'symbol' => 'كرتون'],
    ];

    /**
     * The organization's units, seeding the defaults on first use.
     *
     * @return Collection<int, Unit>
     */
    public function forOrganization(int $organizationId): Collection
    {
        $this->ensureDefaults($organizationId);

        return Unit::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get(['id', 'name', 'symbol']);
    }

    /**
     * Seed only what is missing, so a tenant that renamed or removed one keeps
     * their own list.
     */
    public function ensureDefaults(int $organizationId): void
    {
        if (Unit::query()->where('organization_id', $organizationId)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $unit) {
            Unit::query()->create([
                'organization_id' => $organizationId,
                'name' => $unit['name'],
                'symbol' => $unit['symbol'],
                'conversion_factor' => 1,
                'is_active' => true,
            ]);
        }
    }
}
