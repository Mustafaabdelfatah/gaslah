<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\PlatformPartner;
use App\Models\User;
use App\Services\Platform\PlatformBooks;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformExpenseApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        Sanctum::actingAs($this->owner());
    }

    public function test_recording_an_expense_posts_it_to_the_platform_books(): void
    {
        $id = $this->postJson('/api/admin/expenses', ['category' => 'hosting', 'amount' => 500])
            ->assertCreated()
            ->assertJsonPath('data.amount', '500.00')
            ->assertJsonPath('data.is_partner_funded', false)
            ->json('data.id');

        $booksOrgId = app(PlatformBooks::class)->storedOrgId();
        $this->assertDatabaseHas('journal_entries', [
            'organization_id' => $booksOrgId,
            'ref_type' => 'PlatformExpense',
            'ref_id' => (string) $id,
        ]);
    }

    public function test_an_expense_reduces_the_platform_profit_partners_share(): void
    {
        PlatformPartner::factory()->create(['ownership_percent' => 50]);
        $this->postJson('/api/admin/expenses', ['category' => 'marketing', 'amount' => 400])->assertCreated();

        // No revenue, so the platform is 400 in the red and half of that is the partner's.
        $this->getJson('/api/admin/partners')
            ->assertOk()
            ->assertJsonPath('data.net_income', -400)
            ->assertJsonPath('data.partners.0.share', -200);
    }

    public function test_a_partner_funded_expense_is_owed_back_until_reimbursed(): void
    {
        $partner = PlatformPartner::factory()->create(['ownership_percent' => 0]);

        $id = $this->postJson('/api/admin/expenses', [
            'category' => 'travel',
            'amount' => 300,
            'paid_by_partner_id' => $partner->getKey(),
        ])->assertCreated()->assertJsonPath('data.is_outstanding', true)->json('data.id');

        // With no stake their whole claim is the money they fronted.
        $this->getJson('/api/admin/partners')
            ->assertOk()
            ->assertJsonPath('data.partners.0.outstanding_reimbursement', 300)
            ->assertJsonPath('data.partners.0.net_owed', 300);

        $this->postJson("/api/admin/expenses/{$id}/reimburse")
            ->assertOk()
            ->assertJsonPath('data.is_outstanding', false);

        $this->getJson('/api/admin/partners')
            ->assertOk()
            ->assertJsonPath('data.partners.0.outstanding_reimbursement', 0)
            ->assertJsonPath('data.partners.0.net_owed', 0);
    }

    public function test_an_expense_is_never_reimbursed_twice(): void
    {
        $partner = PlatformPartner::factory()->create();
        $id = $this->postJson('/api/admin/expenses', [
            'category' => 'travel', 'amount' => 100, 'paid_by_partner_id' => $partner->getKey(),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/admin/expenses/{$id}/reimburse")->assertOk();
        // Paying a partner back a second time would be real money lost.
        $this->postJson("/api/admin/expenses/{$id}/reimburse")->assertStatus(409);
    }

    public function test_an_expense_nobody_fronted_has_nothing_to_reimburse(): void
    {
        $id = $this->postJson('/api/admin/expenses', ['category' => 'hosting', 'amount' => 100])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/admin/expenses/{$id}/reimburse")->assertStatus(422);
    }

    public function test_the_listing_filters_to_what_is_still_owed(): void
    {
        $partner = PlatformPartner::factory()->create();
        $this->postJson('/api/admin/expenses', ['category' => 'hosting', 'amount' => 100])->assertCreated();
        $this->postJson('/api/admin/expenses', [
            'category' => 'travel', 'amount' => 250, 'paid_by_partner_id' => $partner->getKey(),
        ])->assertCreated();

        $this->getJson('/api/admin/expenses?outstanding=1')
            ->assertOk()
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.data.data.0.category', 'travel');

        $this->getJson('/api/admin/expenses')
            ->assertOk()
            ->assertJsonPath('data.data.total', 2)
            ->assertJsonPath('data.outstanding_total', 250);
    }

    public function test_expenses_need_the_accounting_permission(): void
    {
        Sanctum::actingAs($this->adminWith(PlatformPermissionEnum::ViewFinance));

        $this->getJson('/api/admin/expenses')->assertStatus(403);
        $this->postJson('/api/admin/expenses', ['category' => 'x', 'amount' => 1])->assertStatus(403);
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
