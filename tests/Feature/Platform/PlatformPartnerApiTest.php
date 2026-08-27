<?php

namespace Tests\Feature\Platform;

use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\PlatformDevice;
use App\Models\PlatformPartner;
use App\Models\User;
use App\Services\Platform\PlatformBooks;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformPartnerApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization] = $this->createTenant();

        Sanctum::actingAs($this->owner());
    }

    public function test_total_active_ownership_cannot_exceed_the_ceiling(): void
    {
        PlatformPartner::factory()->create(['ownership_percent' => 70]);

        $this->postJson('/api/admin/partners', ['name' => 'شريك', 'ownership_percent' => 40])
            ->assertStatus(422);

        // Exactly filling the remainder is allowed.
        $this->postJson('/api/admin/partners', ['name' => 'شريك', 'ownership_percent' => 30])
            ->assertCreated()
            ->assertJsonPath('data.effective_ownership', 30);
    }

    public function test_an_inactive_partner_frees_their_share_of_the_ceiling(): void
    {
        $leaving = PlatformPartner::factory()->create(['ownership_percent' => 60]);

        // While they are active there is no room; deactivating them makes it.
        $this->postJson('/api/admin/partners', ['name' => 'بديل', 'ownership_percent' => 60])->assertStatus(422);

        $this->putJson("/api/admin/partners/{$leaving->getKey()}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.effective_ownership', 0);

        $this->postJson('/api/admin/partners', ['name' => 'بديل', 'ownership_percent' => 60])->assertCreated();
    }

    public function test_reactivating_a_partner_rechecks_the_ceiling(): void
    {
        $dormant = PlatformPartner::factory()->create(['ownership_percent' => 60, 'is_active' => false]);
        PlatformPartner::factory()->create(['ownership_percent' => 60]);

        // Their stake no longer fits alongside the partner who took their place.
        $this->putJson("/api/admin/partners/{$dormant->getKey()}", ['is_active' => true])->assertStatus(422);
    }

    public function test_the_overview_splits_platform_profit_by_stake(): void
    {
        PlatformPartner::factory()->create(['name' => 'شريك أول', 'ownership_percent' => 25]);
        $this->earnPlatformRevenue();

        $response = $this->getJson('/api/admin/partners')->assertOk();

        // The device sale booked 1000 net with no expenses, so profit is 1000.
        $response->assertJsonPath('data.net_income', 1000)
            ->assertJsonPath('data.allocated_ownership', 25)
            ->assertJsonPath('data.partners.0.share', 250)
            ->assertJsonPath('data.partners.0.distributed', 0)
            ->assertJsonPath('data.partners.0.net_owed', 250);
    }

    public function test_a_distribution_is_recorded_with_its_journal_entry(): void
    {
        $partner = PlatformPartner::factory()->create(['ownership_percent' => 50]);
        $this->earnPlatformRevenue();

        $distributionId = $this->postJson("/api/admin/partners/{$partner->getKey()}/distributions", ['amount' => 200])
            ->assertCreated()
            ->assertJsonPath('data.amount', '200.00')
            ->json('data.id');

        // Cash left, so the books must say so: Dr Partner drawings / Cr Bank.
        $booksOrgId = app(PlatformBooks::class)->storedOrgId();
        $entry = JournalEntry::query()
            ->where('organization_id', $booksOrgId)
            ->where('ref_type', 'PlatformPartnerDistribution')
            ->where('ref_id', (string) $distributionId)
            ->first();
        $this->assertNotNull($entry);

        $drawings = Account::query()
            ->where('organization_id', $booksOrgId)
            ->where('system_key', SystemAccountEnum::PartnerDrawings->value)
            ->first();
        $this->assertNotNull($drawings);
        $this->assertSame('3030', $drawings->code);

        $debited = (float) JournalLine::query()
            ->where('journal_entry_id', $entry->getKey())
            ->where('account_id', $drawings->getKey())
            ->sum('debit');
        $this->assertSame(200.0, round($debited, 2));

        // What is still owed drops by what was paid.
        $this->getJson('/api/admin/partners')
            ->assertOk()
            ->assertJsonPath('data.partners.0.distributed', 200)
            ->assertJsonPath('data.partners.0.net_owed', 300);
    }

    public function test_partner_money_is_hidden_from_admins_without_the_permission(): void
    {
        PlatformPartner::factory()->create(['name' => 'شريك', 'ownership_percent' => 40]);

        // An accountant may pick a partner by name for an expense, but may not see stakes
        // or what anyone is owed.
        Sanctum::actingAs($this->adminWith(PlatformPermissionEnum::ManageAccounting));

        $this->getJson('/api/admin/partners/options')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'شريك')
            ->assertJsonMissingPath('data.0.ownership_percent');

        $this->getJson('/api/admin/partners')->assertStatus(403);
        $this->postJson('/api/admin/partners', ['name' => 'x', 'ownership_percent' => 1])->assertStatus(403);
    }

    /**
     * Book real revenue on the platform's own books, so net income is not zero.
     */
    private function earnPlatformRevenue(): void
    {
        $device = PlatformDevice::factory()->create(['price' => 1150]);

        $saleId = $this->postJson('/api/admin/device-sales', [
            'organization_id' => $this->organization->getKey(),
            'lines' => [['device_id' => $device->getKey(), 'qty' => 1]],
            'payment_method' => 'cash',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/device-sales/{$saleId}/confirm")->assertOk();
    }

    private function adminWith(PlatformPermissionEnum $permission): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();
        $user->platformPermissions()->create(['permission' => $permission->value]);

        return $user;
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}
