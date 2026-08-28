<?php

namespace App\Services\Platform;

/**
 * The operator's own settings centre.
 *
 * The store underneath is a flat key/value table, so this is where the platform's settings
 * become a shape: named groups, each with its defaults, merged over whatever is stored.
 * A group read before it was ever saved comes back complete, and a group saved before a
 * new key existed still reads that key at its default rather than as null.
 *
 * It covers only settings that actually drive behaviour. Two things are deliberately not
 * editable here:
 *
 * - `platformBooks`, the reserved organization holding the platform's own accounts.
 *   Pointing it elsewhere by hand would silently re-home the platform's bookkeeping.
 * - the dunning policy, which has a console of its own. A second writer for one setting
 *   is how two screens end up disagreeing about what is in force.
 */
class PlatformSettingsService
{
    /**
     * The editable groups: the store row each lives in, and the shape it is read at.
     *
     * @var array<string, array{key: string, defaults: array<string, mixed>}>
     */
    private const GROUPS = [
        // The operator's identity as the seller on every invoice it issues to a tenant.
        // It reaches the ZATCA QR, so it is the platform's legal name, not a display one.
        'invoicing' => [
            'key' => 'platform',
            'defaults' => ['sellerName' => null, 'sellerVat' => null],
        ],

        // The ceiling on how much of the platform may be owned across active partners.
        'partners' => [
            'key' => 'platform.ownership',
            'defaults' => ['ownershipCeiling' => 100.0],
        ],
    ];

    public function __construct(private readonly PlatformConfigStore $store) {}

    /**
     * @return array<int, string>
     */
    public static function groups(): array
    {
        return array_keys(self::GROUPS);
    }

    public static function isGroup(string $group): bool
    {
        return array_key_exists($group, self::GROUPS);
    }

    /**
     * Every editable group, as the settings centre renders it.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $settings = [];

        foreach (self::groups() as $group) {
            $settings[$group] = $this->group($group);
        }

        return $settings;
    }

    /**
     * One group: what is stored, laid over the defaults.
     *
     * @return array<string, mixed>
     */
    public function group(string $group): array
    {
        $defaults = self::GROUPS[$group]['defaults'] ?? [];

        $stored = $this->store->get(self::GROUPS[$group]['key'] ?? '', []);
        $stored = is_array($stored) ? $stored : [];

        // Only declared keys survive, so a key dropped from a group stops being served the
        // moment it is removed here rather than lingering in the store's JSON.
        return array_intersect_key([...$defaults, ...$stored], $defaults);
    }

    /**
     * Save part of a group.
     *
     * A partial save is a merge: the settings centre saves one card at a time, and an
     * absent key means "leave it alone", never "clear it".
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function save(string $group, array $values): array
    {
        $this->store->put(self::GROUPS[$group]['key'], [...$this->group($group), ...$values]);

        return $this->group($group);
    }

    /*
    |--------------------------------------------------------------------------
    | Typed readers
    |--------------------------------------------------------------------------
    |
    | What the rest of the platform asks, so no caller has to know which row a setting
    | lives in or what it is called there.
    */

    /**
     * The seller on a platform invoice, falling back to the deployment's own configuration
     * while the operator has not named itself.
     *
     * @return array{name: string, vat: string|null}
     */
    public function seller(): array
    {
        $invoicing = $this->group('invoicing');

        return [
            'name' => (string) ($invoicing['sellerName'] ?? config('app.name', 'Gaslah')),
            'vat' => $invoicing['sellerVat'] ?? config('services.platform.seller_vat'),
        ];
    }

    /**
     * The most of the platform that may be owned at once, across active partners.
     */
    public function ownershipCeiling(): float
    {
        return (float) $this->group('partners')['ownershipCeiling'];
    }
}
