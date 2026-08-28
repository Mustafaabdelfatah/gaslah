<?php

namespace Tests\Feature\Crm;

use App\Enum\Crm\LeadStageEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\PlatformSettingsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminLeadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
    }

    /*
    |--------------------------------------------------------------------------
    | The pipeline
    |--------------------------------------------------------------------------
    */

    public function test_a_new_lead_starts_at_the_first_stage_with_the_configured_source(): void
    {
        app(PlatformSettingsService::class)->save('marketing', ['defaultLeadSource' => 'referral']);
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/leads', ['business_name' => 'مغسلة الشرق', 'expected_mrr' => 500])
            ->assertCreated()
            ->assertJsonPath('data.stage', LeadStageEnum::New->value)
            ->assertJsonPath('data.source', 'referral')
            ->assertJsonPath('data.is_converted', false);
    }

    public function test_an_explicit_source_is_not_overridden(): void
    {
        app(PlatformSettingsService::class)->save('marketing', ['defaultLeadSource' => 'referral']);
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/leads', ['business_name' => 'مغسلة', 'source' => 'walk-in'])
            ->assertCreated()
            ->assertJsonPath('data.source', 'walk-in');
    }

    public function test_winning_a_lead_stamps_the_date_once(): void
    {
        $lead = Lead::factory()->create();
        Sanctum::actingAs($this->owner());

        $wonAt = $this->putJson("/api/admin/leads/{$lead->getKey()}", ['stage' => LeadStageEnum::Won->value])
            ->assertOk()
            ->assertJsonPath('data.stage', LeadStageEnum::Won->value)
            ->json('data.won_at');

        $this->assertNotNull($wonAt);

        // It is when the deal closed, not when someone last touched the record.
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->putJson("/api/admin/leads/{$lead->getKey()}", ['contact_name' => 'أحمد'])
            ->assertOk()
            ->assertJsonPath('data.won_at', $wonAt);
        Carbon::setTestNow();
    }

    public function test_losing_a_lead_requires_a_reason(): void
    {
        $lead = Lead::factory()->create();
        Sanctum::actingAs($this->owner());

        // Losing without a reason teaches the operator nothing, which is the point of
        // recording it.
        $this->putJson("/api/admin/leads/{$lead->getKey()}", ['stage' => LeadStageEnum::Lost->value])
            ->assertStatus(422);

        $this->putJson("/api/admin/leads/{$lead->getKey()}", [
            'stage' => LeadStageEnum::Lost->value, 'lost_reason' => 'اختار منافس',
        ])->assertOk()->assertJsonPath('data.lost_reason', 'اختار منافس');
    }

    public function test_a_lead_moved_back_out_of_lost_drops_the_reason(): void
    {
        $lead = Lead::factory()->stage(LeadStageEnum::Lost)->create(['lost_reason' => 'السعر']);
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/leads/{$lead->getKey()}", ['stage' => LeadStageEnum::Contacted->value])
            ->assertOk()
            ->assertJsonPath('data.lost_reason', null);
    }

    public function test_a_lead_cannot_be_owned_by_someone_who_is_not_a_platform_admin(): void
    {
        [, $branch] = $this->createTenant();
        $staff = $this->createStaff($branch);

        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/leads', [
            'business_name' => 'مغسلة', 'owner_id' => $staff->getKey(),
        ])->assertStatus(422);
    }

    public function test_the_board_carries_the_pipeline_numbers(): void
    {
        Lead::factory()->create(['expected_mrr' => 300]);
        Lead::factory()->stage(LeadStageEnum::Qualified)->create(['expected_mrr' => 700]);
        Lead::factory()->stage(LeadStageEnum::Won)->create(['expected_mrr' => 900, 'won_at' => Carbon::now()]);
        Lead::factory()->stage(LeadStageEnum::Lost)->create(['expected_mrr' => 500]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/leads')
            ->assertOk()
            ->assertJsonPath('data.data.total', 4)
            ->assertJsonPath('data.summary.total', 4)
            ->assertJsonPath('data.summary.open', 2)
            ->assertJsonPath('data.summary.won_this_month', 1)
            // Only open leads are worth anything: a won one is revenue, a lost one nothing.
            ->assertJsonPath('data.summary.pipeline_value', 1000);
    }

    public function test_the_board_can_be_narrowed_to_what_is_still_being_chased(): void
    {
        Lead::factory()->create();
        Lead::factory()->stage(LeadStageEnum::Won)->create();

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/leads?open=1')->assertOk()->assertJsonPath('data.data.total', 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Conversion
    |--------------------------------------------------------------------------
    */

    public function test_converting_a_lead_provisions_a_tenant_and_marks_it_won(): void
    {
        $lead = Lead::factory()->create(['business_name' => 'مغسلة النخيل']);
        Sanctum::actingAs($this->owner());

        $response = $this->postJson("/api/admin/leads/{$lead->getKey()}/convert", [
            'admin_name' => 'سالم',
            'email' => 'salem@example.com',
            'password' => 'password123',
        ])
            ->assertCreated()
            ->assertJsonPath('data.stage', LeadStageEnum::Won->value)
            ->assertJsonPath('data.is_converted', true);

        $organizationId = $response->json('data.converted_organization_id');

        // The organization takes the lead's own name, and gets a main branch and an owner.
        $this->assertSame('مغسلة النخيل', Organization::query()->find($organizationId)?->name);
        $this->assertDatabaseHas('branches', ['organization_id' => $organizationId, 'code' => 'MAIN']);
        $this->assertDatabaseHas('users', ['email' => 'salem@example.com']);
    }

    public function test_a_lead_cannot_be_converted_twice(): void
    {
        $lead = Lead::factory()->create();
        Sanctum::actingAs($this->owner());

        $payload = ['admin_name' => 'سالم', 'email' => 'salem@example.com', 'password' => 'password123'];

        $this->postJson("/api/admin/leads/{$lead->getKey()}/convert", $payload)->assertCreated();

        $this->postJson("/api/admin/leads/{$lead->getKey()}/convert", [
            ...$payload, 'email' => 'other@example.com',
        ])->assertStatus(409);

        $this->assertSame(1, Organization::query()->where('name', $lead->business_name)->count());
    }

    public function test_editing_a_converted_lead_back_out_of_won_still_refuses_a_second_conversion(): void
    {
        $lead = Lead::factory()->create();
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/leads/{$lead->getKey()}/convert", [
            'admin_name' => 'سالم', 'email' => 'salem@example.com', 'password' => 'password123',
        ])->assertCreated();

        // The column is the guard, not the stage: the same business must not be sold twice.
        $this->putJson("/api/admin/leads/{$lead->getKey()}", ['stage' => LeadStageEnum::Contacted->value])->assertOk();

        $this->postJson("/api/admin/leads/{$lead->getKey()}/convert", [
            'admin_name' => 'سالم', 'email' => 'again@example.com', 'password' => 'password123',
        ])->assertStatus(409);
    }

    public function test_conversion_leaves_nothing_behind_when_the_owner_email_is_taken(): void
    {
        $existing = $this->createUser(['email' => 'taken@example.com']);
        $lead = Lead::factory()->create();
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/leads/{$lead->getKey()}/convert", [
            'admin_name' => 'سالم', 'email' => $existing->email, 'password' => 'password123',
        ])->assertStatus(422);

        $this->assertFalse($lead->refresh()->isConverted());
        $this->assertSame(0, Organization::query()->where('name', $lead->business_name)->count());
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    public function test_the_pipeline_needs_the_leads_permission(): void
    {
        Sanctum::actingAs($this->adminWithout());

        $this->getJson('/api/admin/leads')->assertStatus(403);
        $this->postJson('/api/admin/leads', ['business_name' => 'x'])->assertStatus(403);
    }

    public function test_a_tenant_staff_member_cannot_reach_the_pipeline(): void
    {
        [, $branch] = $this->createTenant();
        $this->actingAsStaff($this->createStaff($branch));

        $this->getJson('/api/admin/leads')->assertStatus(403);
    }

    public function test_a_sales_admin_may_work_the_pipeline(): void
    {
        Sanctum::actingAs($this->salesAdmin());

        $this->postJson('/api/admin/leads', ['business_name' => 'مغسلة'])->assertCreated();
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
     * A platform admin holding nothing.
     */
    private function adminWithout(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();

        return $user;
    }

    private function salesAdmin(): User
    {
        $user = $this->adminWithout();
        $user->forceFill(['platform_role' => PlatformRoleEnum::Sales->value])->save();
        $user->platformPermissions()->create(['permission' => PlatformPermissionEnum::ManageLeads->value]);

        return $user;
    }
}
