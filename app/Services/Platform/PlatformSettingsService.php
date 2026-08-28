<?php

namespace App\Services\Platform;

use App\Enum\Platform\PlatformAnnouncementLevelEnum;

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

        // Broadcast banners: what a new one defaults to, and how many the tenant's
        // dashboard shows at once.
        'announcements' => [
            'key' => 'platform.announcements',
            'defaults' => [
                'defaultLevel' => 'info',
                'defaultDurationDays' => 14,
                'tenantNoticeLimit' => 10,
            ],
        ],

        // Where a lead entered by hand is assumed to have come from.
        'marketing' => [
            'key' => 'platform.marketing',
            'defaults' => ['defaultLeadSource' => 'manual'],
        ],

        // How support runs: the categories a tenant may file under, how long the operator
        // may leave a ticket unanswered before it counts as breached, and the automatic
        // acknowledgement a new ticket gets.
        'support' => [
            'key' => 'platform.support',
            'defaults' => [
                'categories' => [],
                'slaResponseMinutes' => 240,
                'autoReplyEnabled' => false,
                'autoReplyText' => null,
            ],
        ],
    ];

    /**
     * Groups already read this request. The store is a table, and a listing that asks the
     * same group once per row would turn one setting into an N+1.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $resolved = [];

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
        if (array_key_exists($group, $this->resolved)) {
            return $this->resolved[$group];
        }

        $defaults = self::GROUPS[$group]['defaults'] ?? [];

        $stored = $this->store->get(self::GROUPS[$group]['key'] ?? '', []);
        $stored = is_array($stored) ? $stored : [];

        // Only declared keys survive, so a key dropped from a group stops being served the
        // moment it is removed here rather than lingering in the store's JSON.
        return $this->resolved[$group] = array_intersect_key([...$defaults, ...$stored], $defaults);
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

        // The row just changed underneath the memo.
        unset($this->resolved[$group]);

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

    /**
     * What a broadcast banner defaults to, and how many a tenant sees at once.
     *
     * @return array{defaultLevel: string, defaultDurationDays: int, tenantNoticeLimit: int}
     */
    public function announcements(): array
    {
        $announcements = $this->group('announcements');

        $level = (string) $announcements['defaultLevel'];

        return [
            // A level the operator has since removed falls back rather than writing a
            // value the column's own CHECK would reject.
            'defaultLevel' => PlatformAnnouncementLevelEnum::tryFrom($level) === null
                ? PlatformAnnouncementLevelEnum::Info->value
                : $level,
            'defaultDurationDays' => (int) $announcements['defaultDurationDays'],
            'tenantNoticeLimit' => (int) $announcements['tenantNoticeLimit'],
        ];
    }

    /**
     * Where a lead entered by hand is assumed to have come from.
     *
     * @return array{defaultLeadSource: string}
     */
    public function marketing(): array
    {
        $source = trim((string) $this->group('marketing')['defaultLeadSource']);

        return ['defaultLeadSource' => $source === '' ? 'manual' : $source];
    }

    /**
     * How support is configured.
     *
     * @return array{categories: array<int, string>, slaResponseMinutes: int, autoReplyEnabled: bool, autoReplyText: ?string}
     */
    public function support(): array
    {
        $support = $this->group('support');

        return [
            'categories' => is_array($support['categories']) ? array_values($support['categories']) : [],
            'slaResponseMinutes' => (int) $support['slaResponseMinutes'],
            'autoReplyEnabled' => (bool) $support['autoReplyEnabled'],
            'autoReplyText' => $support['autoReplyText'],
        ];
    }
}
