<?php

namespace App\Models;

use App\Scopes\Tenancy\OrganizationScopes;
use App\Services\Platform\PlatformBooks;
use App\Services\Platform\PlatformConfigStore;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

/**
 * A tenant: one laundry business, owning one or more branches.
 *
 * Every tenant-owned record carries this model's key, and the platform controls
 * (suspension, feature overrides, seat limits) live here rather than on the
 * subscription so an operator can act on a tenant that has no plan at all.
 */
class Organization extends BaseModel
{
    use HasFactory, LogsActivityOptions, OrganizationScopes;

    protected $fillable = [
        'legacy_cuid',
        'name',
        'slug',
        'custom_domain',
        'default_currency',
        'tax_rate',
        'phone',
        'email',
        'address',
        'cr_number',
        'vat_number',
        'receipt_footer',
        'receipt_width',
        'brand_primary',
        'brand_accent',
        'logo_url',
        'settings',
        'is_suspended',
        'feature_overrides',
        'max_branches_override',
        'max_users_override',
        'admin_follow_up',
        'admin_tags',
        'account_credit',
        'payout_config',
        'archived_at',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:2',
        'account_credit' => 'decimal:2',
        'receipt_width' => 'integer',
        'max_branches_override' => 'integer',
        'max_users_override' => 'integer',
        'is_suspended' => 'boolean',
        'admin_follow_up' => 'boolean',
        'settings' => 'array',
        'feature_overrides' => 'array',
        'admin_tags' => 'array',
        'payout_config' => 'array',
        'archived_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Casts && Set Custom Attributes
    |--------------------------------------------------------------------------
    */
    protected function slug(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value === null ? null : Str::lower($value),
        );
    }

    protected function customDomain(): Attribute
    {
        return Attribute::make(
            set: static fn (?string $value) => $value === null ? null : Str::lower($value),
        );
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether this organization is the reserved one that holds the platform's own books
     * (never billed, never listed in the tenant directory).
     */
    public function isPlatformOrg(): bool
    {
        $reservedId = self::reservedBooksOrgId();

        return $reservedId !== null && $reservedId === (int) $this->getKey();
    }

    /**
     * The id of the reserved platform-books organization: the persisted value set by
     * {@see PlatformBooks}, or a static env fallback.
     */
    public static function reservedBooksOrgId(): ?int
    {
        $stored = app(PlatformConfigStore::class)->platformBooksOrgId();

        if ($stored !== null) {
            return $stored;
        }

        $configured = config('services.platform.books_org_id');

        return $configured === null ? null : (int) $configured;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function platformSubscription(): HasOne
    {
        return $this->hasOne(PlatformSubscription::class);
    }
}
