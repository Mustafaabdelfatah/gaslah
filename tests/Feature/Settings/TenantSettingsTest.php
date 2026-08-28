<?php

namespace Tests\Feature\Settings;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
    }

    /*
    |--------------------------------------------------------------------------
    | Who may read and who may write
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, array{0: string}>
     */
    public static function panels(): array
    {
        return [
            'business' => ['business'],
            'portal' => ['portal'],
            'credit' => ['credit'],
            'notifications' => ['notifications'],
        ];
    }

    #[DataProvider('panels')]
    public function test_a_cashier_cannot_even_read_a_settings_panel(string $panel): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson("/api/settings/{$panel}")->assertForbidden();
    }

    #[DataProvider('panels')]
    public function test_a_branch_manager_reads_but_cannot_write(string $panel): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));

        $this->getJson("/api/settings/{$panel}")->assertOk();

        // Refused on authorization, before any field is even looked at — an unauthorised
        // caller must never learn the shape of the payload from a 422.
        $this->putJson("/api/settings/{$panel}", [])->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Business profile
    |--------------------------------------------------------------------------
    */

    public function test_the_general_manager_updates_the_commercial_profile(): void
    {
        $this->asOwner();

        $this->putJson('/api/settings/business', [
            'name' => 'مغسلة النور',
            'default_currency' => 'SAR',
            'tax_rate' => 15,
            'receipt_width' => 80,
            'vat_number' => '310000000000003',
            'brand_primary' => '#AABBCC',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'مغسلة النور')
            ->assertJsonPath('data.receipt_width', 80)
            // Stored in one case, so the same colour never appears as two values.
            ->assertJsonPath('data.brand_primary', '#aabbcc');
    }

    public function test_a_blank_optional_field_is_stored_as_null_not_an_empty_string(): void
    {
        $this->asOwner();
        $this->organization->forceFill(['phone' => '0500000000'])->save();

        $this->putJson('/api/settings/business', [
            ...$this->businessPayload(),
            'phone' => '   ',
        ])->assertOk()->assertJsonPath('data.phone', null);

        $this->assertNull($this->organization->refresh()->phone);
    }

    public function test_a_receipt_width_the_product_cannot_print_is_refused(): void
    {
        $this->asOwner();

        $this->putJson('/api/settings/business', [...$this->businessPayload(), 'receipt_width' => 100])
            ->assertStatus(422);
    }

    public function test_a_malformed_brand_colour_is_refused(): void
    {
        $this->asOwner();

        $this->putJson('/api/settings/business', [...$this->businessPayload(), 'brand_primary' => 'red'])
            ->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Portal identity
    |--------------------------------------------------------------------------
    */

    public function test_the_portal_panel_saves_the_columns_and_the_config_together(): void
    {
        $this->asOwner();

        $this->putJson('/api/settings/portal', [
            'slug' => 'AlNoor-Laundry',
            'logo_url' => '/uploads/logo.png',
            'custom_domain' => 'Portal.AlNoor.SA',
            'show_offers' => true,
            'terms_url' => 'https://example.com/terms',
        ])
            ->assertOk()
            // Both are lower-cased before they are stored or matched.
            ->assertJsonPath('data.slug', 'alnoor-laundry')
            ->assertJsonPath('data.custom_domain', 'portal.alnoor.sa')
            ->assertJsonPath('data.show_offers', true)
            ->assertJsonPath('data.terms_url', 'https://example.com/terms');
    }

    public function test_clearing_the_slug_keeps_the_current_one(): void
    {
        $this->asOwner();
        $this->organization->forceFill(['slug' => 'keep-me'])->save();

        $this->putJson('/api/settings/portal', ['show_offers' => false])
            ->assertOk()
            // A saved portal link must not break because an unrelated field was saved.
            ->assertJsonPath('data.slug', 'keep-me');
    }

    public function test_a_slug_already_taken_by_another_laundry_is_refused(): void
    {
        $this->createOrganization(['slug' => 'taken']);
        $this->asOwner();

        $this->putJson('/api/settings/portal', ['slug' => 'taken', 'show_offers' => false])
            ->assertStatus(422);
    }

    public function test_a_laundry_may_keep_its_own_slug(): void
    {
        $this->organization->forceFill(['slug' => 'mine'])->save();
        $this->asOwner();

        $this->putJson('/api/settings/portal', ['slug' => 'mine', 'show_offers' => true])
            ->assertOk()
            ->assertJsonPath('data.slug', 'mine');
    }

    public function test_a_logo_url_that_could_execute_is_refused(): void
    {
        $this->asOwner();

        foreach (['javascript:alert(1)', 'data:text/html;base64,PHNjcmlwdD4='] as $hostile) {
            $this->putJson('/api/settings/portal', ['logo_url' => $hostile, 'show_offers' => false])
                ->assertStatus(422);
        }
    }

    public function test_saving_the_portal_panel_leaves_other_settings_untouched(): void
    {
        $this->asOwner();
        $this->organization->forceFill(['settings' => ['unrelated' => 'keep']])->save();

        $this->putJson('/api/settings/portal', ['show_offers' => true])->assertOk();

        $this->assertSame('keep', $this->organization->refresh()->settings['unrelated']);
    }

    /*
    |--------------------------------------------------------------------------
    | Credit & notifications
    |--------------------------------------------------------------------------
    */

    public function test_a_panel_never_saved_reads_its_defaults(): void
    {
        $this->asOwner();

        $this->getJson('/api/settings/credit')
            ->assertOk()
            ->assertJsonPath('data.is_enabled', false)
            ->assertJsonPath('data.default_limit', '0.00');

        $this->getJson('/api/settings/notifications')
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.late_orders', true)
            // An unpaid order is the normal state of a deferred sale, so it is off.
            ->assertJsonPath('data.unpaid_orders', false);
    }

    public function test_the_credit_panel_is_saved_and_read_back(): void
    {
        $this->asOwner();

        $this->putJson('/api/settings/credit', ['is_enabled' => true, 'default_limit' => 5000])
            ->assertOk()
            ->assertJsonPath('data.is_enabled', true)
            ->assertJsonPath('data.default_limit', '5000.00');

        $this->getJson('/api/settings/credit')->assertOk()->assertJsonPath('data.default_limit', '5000.00');

        $this->assertDatabaseHas('organization_credit_settings', [
            'organization_id' => $this->organization->getKey(), 'default_limit' => 5000,
        ]);
    }

    public function test_a_credit_limit_beyond_the_columns_ceiling_is_refused(): void
    {
        $this->asOwner();

        $this->putJson('/api/settings/credit', ['is_enabled' => true, 'default_limit' => 10000001])
            ->assertStatus(422);
    }

    public function test_the_notification_panel_is_saved_whole(): void
    {
        $this->asOwner();

        $this->putJson('/api/settings/notifications', [
            'is_enabled' => true,
            'late_orders' => false,
            'delivery_requests' => true,
            'ready_orders' => false,
            'online_payments' => true,
            'unpaid_orders' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.late_orders', false)
            ->assertJsonPath('data.unpaid_orders', true);
    }

    public function test_a_partial_notification_payload_is_refused(): void
    {
        $this->asOwner();

        // Saving the panel with a switch missing would silently read as "off".
        $this->putJson('/api/settings/notifications', ['is_enabled' => true])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Isolation
    |--------------------------------------------------------------------------
    */

    public function test_a_laundry_only_ever_reads_its_own_settings(): void
    {
        $other = $this->createOrganization(['name' => 'Someone Else']);
        $this->asOwner();

        // No organization id is accepted from the client at all: the panel is always the
        // caller's own.
        $this->getJson("/api/settings/business?organization_id={$other->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->organization->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function asOwner(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    /**
     * @return array<string, mixed>
     */
    private function businessPayload(): array
    {
        return [
            'name' => $this->organization->name,
            'default_currency' => 'SAR',
            'tax_rate' => 15,
            'receipt_width' => 58,
        ];
    }
}
