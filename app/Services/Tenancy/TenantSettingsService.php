<?php

namespace App\Services\Tenancy;

use App\Models\Organization;
use App\Models\OrganizationCreditSetting;
use App\Models\OrganizationNotificationSetting;
use Illuminate\Support\Facades\DB;

/**
 * Writing an organization's own settings.
 *
 * Each group is saved on its own: the settings screen has several panels, and saving one
 * must never overwrite a value another panel holds — least of all when two people are
 * editing at once.
 */
class TenantSettingsService
{
    /**
     * Update the commercial profile: the invoice details, the tax rate and the brand.
     *
     * @param  array<string, mixed>  $profile
     */
    public function updateBusiness(Organization $organization, array $profile): Organization
    {
        $organization->update($profile);

        return $organization->refresh();
    }

    /**
     * Update the customer portal's identity.
     *
     * The organization's columns and the portal block of its settings are written in one
     * transaction: a half-applied save would leave the portal reachable at a slug whose
     * branding says something else.
     *
     * @param  array{show_offers: bool, terms_url: ?string, privacy_url: ?string}  $config
     */
    public function updatePortal(
        Organization $organization,
        ?string $logoUrl,
        ?string $slug,
        ?string $customDomain,
        array $config,
    ): Organization {
        return DB::transaction(function () use ($organization, $logoUrl, $slug, $customDomain, $config) {
            $organization->update([
                'logo_url' => $logoUrl,
                // A blank slug keeps the current one. Clearing it would break every portal
                // link a customer has already saved.
                'slug' => $slug ?? $organization->slug,
                'custom_domain' => $customDomain,
                'settings' => [
                    ...(is_array($organization->settings) ? $organization->settings : []),
                    'portal' => $config,
                ],
            ]);

            return $organization->refresh();
        });
    }

    /**
     * @param  array{is_enabled: bool, default_limit: float}  $settings
     */
    public function updateCredit(Organization $organization, array $settings): OrganizationCreditSetting
    {
        return OrganizationCreditSetting::query()->updateOrCreate(
            ['organization_id' => $organization->getKey()],
            $settings,
        )->refresh();
    }

    /**
     * @param  array<string, bool>  $switches
     */
    public function updateNotifications(Organization $organization, array $switches): OrganizationNotificationSetting
    {
        return OrganizationNotificationSetting::query()->updateOrCreate(
            ['organization_id' => $organization->getKey()],
            $switches,
        )->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Reads
    |--------------------------------------------------------------------------
    |
    | An organization that has never opened a settings panel has no row for it. Both reads
    | answer with an unsaved model carrying the schema's defaults, so the screen renders
    | the same before and after the first save and nothing has to be seeded.
    */

    public function credit(Organization $organization): OrganizationCreditSetting
    {
        return OrganizationCreditSetting::query()
            ->firstOrNew(['organization_id' => $organization->getKey()]);
    }

    public function notifications(Organization $organization): OrganizationNotificationSetting
    {
        return OrganizationNotificationSetting::query()
            ->firstOrNew(['organization_id' => $organization->getKey()]);
    }
}
