<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\User;
use App\Services\Platform\PlatformConfigStore;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminConfigApiTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | Who may look at all
    |--------------------------------------------------------------------------
    */

    public function test_the_centre_is_the_owners_alone_reads_included(): void
    {
        Sanctum::actingAs($this->configManager());

        // Holding manage_config is deliberately not enough: these settings decide who the
        // platform is on the invoices it issues.
        $this->getJson('/api/admin/config')->assertStatus(403);
        $this->putJson('/api/admin/config/invoicing', ['sellerName' => 'X'])->assertStatus(403);
    }

    public function test_a_tenant_staff_member_cannot_reach_it(): void
    {
        Sanctum::actingAs($this->createUser());

        $this->getJson('/api/admin/config')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Reading
    |--------------------------------------------------------------------------
    */

    public function test_a_group_never_saved_reads_its_defaults(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/config/partners')
            ->assertOk()
            ->assertJsonPath('data.ownershipCeiling', 100);

        $this->getJson('/api/admin/config/invoicing')
            ->assertOk()
            ->assertJsonPath('data.sellerName', null);
    }

    public function test_the_index_lists_every_group(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/config')
            ->assertOk()
            ->assertJsonPath('data.groups', PlatformSettingsService::groups())
            ->assertJsonPath('data.settings.partners.ownershipCeiling', 100);
    }

    public function test_a_group_that_is_not_ours_does_not_exist(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/config/nonsense')->assertNotFound();
        $this->putJson('/api/admin/config/nonsense', [])->assertNotFound();
    }

    public function test_the_reserved_books_row_is_not_reachable_through_the_centre(): void
    {
        app(PlatformConfigStore::class)->setPlatformBooksOrgId(42);
        Sanctum::actingAs($this->owner());

        // Pointing this elsewhere by hand would silently re-home the platform's own
        // accounting, so it is not a group at all.
        $this->getJson('/api/admin/config/platformBooks')->assertNotFound();
        $this->putJson('/api/admin/config/platformBooks', ['orgId' => 1])->assertNotFound();

        $this->assertSame(42, app(PlatformConfigStore::class)->platformBooksOrgId());
    }

    /*
    |--------------------------------------------------------------------------
    | Writing
    |--------------------------------------------------------------------------
    */

    public function test_saving_a_group_reaches_the_service_that_reads_it(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson('/api/admin/config/invoicing', [
            'sellerName' => 'Gaslah Technologies',
            'sellerVat' => '310000000000003',
        ])->assertOk()->assertJsonPath('data.sellerName', 'Gaslah Technologies');

        // The point of the screen: what it saves is what the invoicing chain then bills as.
        $seller = app(PlatformSettingsService::class)->seller();
        $this->assertSame('Gaslah Technologies', $seller['name']);
        $this->assertSame('310000000000003', $seller['vat']);
    }

    public function test_the_ownership_ceiling_saved_here_is_the_one_partners_are_held_to(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson('/api/admin/config/partners', ['ownershipCeiling' => 60])->assertOk();

        $this->assertSame(60.0, app(PlatformSettingsService::class)->ownershipCeiling());
    }

    public function test_a_partial_save_leaves_the_rest_of_the_group_alone(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson('/api/admin/config/invoicing', [
            'sellerName' => 'Gaslah', 'sellerVat' => '310000000000003',
        ])->assertOk();

        // The centre saves one card at a time, so an absent key means "leave it alone".
        $this->putJson('/api/admin/config/invoicing', ['sellerName' => 'Gaslah KSA'])
            ->assertOk()
            ->assertJsonPath('data.sellerName', 'Gaslah KSA')
            ->assertJsonPath('data.sellerVat', '310000000000003');
    }

    public function test_a_malformed_vat_number_is_refused(): void
    {
        Sanctum::actingAs($this->owner());

        // It reaches the ZATCA QR on every invoice the platform issues.
        $this->putJson('/api/admin/config/invoicing', ['sellerVat' => '123'])->assertStatus(422);
    }

    public function test_a_ceiling_above_a_whole_platform_is_refused(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson('/api/admin/config/partners', ['ownershipCeiling' => 140])->assertStatus(422);
    }

    public function test_a_key_that_is_not_part_of_the_group_is_ignored(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson('/api/admin/config/partners', [
            'ownershipCeiling' => 80,
            'somethingElse' => 'ignored',
        ])->assertOk()->assertJsonMissingPath('data.somethingElse');
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }

    /**
     * A platform admin holding manage_config, but not the owner.
     */
    private function configManager(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Support->value])->save();
        $user->platformPermissions()->create(['permission' => PlatformPermissionEnum::ManageConfig->value]);

        return $user;
    }
}
