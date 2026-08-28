<?php

namespace Tests\Feature\Crm;

use App\Enum\Crm\CrmNoteKindEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\CrmNote;
use App\Models\Lead;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Platform\PlatformBooks;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCrmApiTest extends TestCase
{
    use RefreshDatabase;

    private PlatformPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->plan = PlatformPlan::factory()->create();
    }

    /*
    |--------------------------------------------------------------------------
    | The attention list
    |--------------------------------------------------------------------------
    */

    public function test_the_list_names_why_each_tenant_is_on_it(): void
    {
        $pastDue = $this->tenantWithSubscription(PlatformSubscriptionStatusEnum::PastDue);
        $trialEnding = $this->tenantWithSubscription(
            PlatformSubscriptionStatusEnum::Trial,
            ['current_period_end' => Carbon::now()->addDays(3)],
        );
        $healthy = $this->tenantWithSubscription(
            PlatformSubscriptionStatusEnum::Active,
            ['current_period_end' => Carbon::now()->addMonth()],
        );

        Sanctum::actingAs($this->owner());

        $flagged = collect($this->getJson('/api/admin/crm')->assertOk()->json('data.attention'))
            ->keyBy(fn (array $entry) => $entry['tenant']['id']);

        $this->assertSame(['past_due'], $flagged[$pastDue->getKey()]['reasons']);
        $this->assertSame(['trial_ending'], $flagged[$trialEnding->getKey()]['reasons']);
        $this->assertArrayNotHasKey($healthy->getKey(), $flagged->all());
    }

    public function test_a_tenant_in_trouble_twice_shows_both_reasons(): void
    {
        $organization = $this->tenantWithSubscription(PlatformSubscriptionStatusEnum::PastDue);
        $organization->forceFill(['is_suspended' => true])->save();

        Sanctum::actingAs($this->owner());

        $entry = collect($this->getJson('/api/admin/crm')->assertOk()->json('data.attention'))
            ->firstWhere('tenant.id', $organization->getKey());

        // The operator needs both, not whichever query happened to match first.
        $this->assertEqualsCanonicalizing(['past_due', 'suspended'], $entry['reasons']);
    }

    public function test_a_lapsed_period_under_a_paying_status_counts_as_expired(): void
    {
        $organization = $this->tenantWithSubscription(
            PlatformSubscriptionStatusEnum::Active,
            ['current_period_end' => Carbon::now()->subDay()],
        );

        Sanctum::actingAs($this->owner());

        $entry = collect($this->getJson('/api/admin/crm')->assertOk()->json('data.attention'))
            ->firstWhere('tenant.id', $organization->getKey());

        // Exactly the account nobody notices has stopped paying.
        $this->assertSame(['expired'], $entry['reasons']);
    }

    public function test_the_reserved_platform_books_account_is_never_on_the_list(): void
    {
        // Provision the platform's own bookkeeping organization and suspend it, which
        // would put any ordinary tenant on the list.
        $books = app(PlatformBooks::class)->organization();
        $books->forceFill(['is_suspended' => true])->save();

        Sanctum::actingAs($this->owner());

        $flagged = collect($this->getJson('/api/admin/crm')->assertOk()->json('data.attention'))
            ->pluck('tenant.id');

        // It is the platform's own books, not a customer: it belongs in no tenant listing.
        $this->assertNotContains($books->getKey(), $flagged->all());
    }

    /*
    |--------------------------------------------------------------------------
    | Notes and tasks
    |--------------------------------------------------------------------------
    */

    public function test_a_note_is_recorded_against_a_tenant(): void
    {
        $organization = $this->tenantWithSubscription(PlatformSubscriptionStatusEnum::Active);
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/crm/notes', [
            'organization_id' => $organization->getKey(),
            'kind' => CrmNoteKindEnum::Call->value,
            'body' => 'اتصلنا وتم الاتفاق على التجديد',
        ])
            ->assertCreated()
            ->assertJsonPath('data.kind', CrmNoteKindEnum::Call->value)
            ->assertJsonPath('data.organization', $organization->name)
            ->assertJsonPath('data.is_done', false);
    }

    public function test_a_note_must_have_exactly_one_subject(): void
    {
        $organization = $this->tenantWithSubscription(PlatformSubscriptionStatusEnum::Active);
        $lead = Lead::factory()->create();
        Sanctum::actingAs($this->owner());

        // Attached to neither, it belongs nowhere and would never surface again.
        $this->postJson('/api/admin/crm/notes', ['body' => 'ملاحظة'])->assertStatus(422);

        // Attached to both, it would appear on two timelines saying different things.
        $this->postJson('/api/admin/crm/notes', [
            'body' => 'ملاحظة',
            'lead_id' => $lead->getKey(),
            'organization_id' => $organization->getKey(),
        ])->assertStatus(422);
    }

    public function test_a_task_is_completed_once_and_the_time_is_not_rewritten(): void
    {
        Sanctum::actingAs($this->owner());

        $id = $this->postJson('/api/admin/crm/notes', [
            'lead_id' => Lead::factory()->create()->getKey(),
            'kind' => CrmNoteKindEnum::Task->value,
            'body' => 'متابعة بعد أسبوع',
            'due_at' => Carbon::now()->addWeek()->toDateTimeString(),
        ])->assertCreated()->json('data.id');

        $doneAt = $this->postJson("/api/admin/crm/notes/{$id}/done")
            ->assertOk()
            ->assertJsonPath('data.is_done', true)
            ->json('data.done_at');

        // Re-marking would rewrite when the work was actually finished.
        Carbon::setTestNow(Carbon::now()->addDay());
        $this->postJson("/api/admin/crm/notes/{$id}/done")->assertOk()->assertJsonPath('data.done_at', $doneAt);
        Carbon::setTestNow();
    }

    public function test_only_a_task_can_be_marked_done(): void
    {
        Sanctum::actingAs($this->owner());

        $id = $this->postJson('/api/admin/crm/notes', [
            'lead_id' => Lead::factory()->create()->getKey(),
            'kind' => CrmNoteKindEnum::Call->value,
            'body' => 'اتصال',
        ])->assertCreated()->json('data.id');

        // Marking a record of a phone call "done" is meaningless — it already happened.
        $this->postJson("/api/admin/crm/notes/{$id}/done")->assertStatus(422);
    }

    public function test_an_overdue_task_is_flagged(): void
    {
        $note = CrmNote::query()->create([
            'lead_id' => Lead::factory()->create()->getKey(),
            'kind' => CrmNoteKindEnum::Task->value,
            'body' => 'متأخرة',
            'due_at' => Carbon::now()->subWeek(),
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/crm/notes')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $note->getKey())
            ->assertJsonPath('data.data.0.is_overdue', true);
    }

    public function test_the_log_can_be_narrowed_to_outstanding_tasks(): void
    {
        $leadId = Lead::factory()->create()->getKey();

        CrmNote::query()->create(['lead_id' => $leadId, 'kind' => 'task', 'body' => 'مفتوحة']);
        CrmNote::query()->create(['lead_id' => $leadId, 'kind' => 'task', 'body' => 'منجزة', 'done_at' => Carbon::now()]);
        CrmNote::query()->create(['lead_id' => $leadId, 'kind' => 'call', 'body' => 'اتصال']);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/crm/notes?pending=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.body', 'مفتوحة');
    }

    public function test_a_note_added_through_a_lead_lands_on_that_lead(): void
    {
        $lead = Lead::factory()->create();
        $other = Lead::factory()->create();
        Sanctum::actingAs($this->owner());

        // The lead is the one in the path, whatever the body claims.
        $this->postJson("/api/admin/leads/{$lead->getKey()}/notes", [
            'body' => 'ملاحظة', 'lead_id' => $other->getKey(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.lead_id', $lead->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Permissions
    |--------------------------------------------------------------------------
    */

    public function test_the_desk_needs_the_crm_permission(): void
    {
        Sanctum::actingAs($this->adminWithout());

        $this->getJson('/api/admin/crm')->assertStatus(403);
        $this->postJson('/api/admin/crm/notes', ['body' => 'x'])->assertStatus(403);
    }

    public function test_a_tenant_staff_member_cannot_reach_the_desk(): void
    {
        [, $branch] = $this->createTenant();
        $this->actingAsStaff($this->createStaff($branch));

        $this->getJson('/api/admin/crm')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tenantWithSubscription(
        PlatformSubscriptionStatusEnum $status,
        array $attributes = [],
    ): Organization {
        [$organization] = $this->createTenant();

        PlatformSubscription::query()->create([
            'organization_id' => $organization->getKey(),
            'plan_id' => $this->plan->getKey(),
            'status' => $status->value,
            'started_at' => Carbon::now()->subMonth(),
            ...$attributes,
        ]);

        return $organization;
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }

    private function adminWithout(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();

        return $user;
    }
}
