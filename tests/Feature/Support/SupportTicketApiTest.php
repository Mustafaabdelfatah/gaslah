<?php

namespace Tests\Feature\Support;

use App\Enum\Support\SupportPriorityEnum;
use App\Enum\Support\SupportTicketStatusEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Platform\PlatformSettingsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportTicketApiTest extends TestCase
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
    | The laundry's side
    |--------------------------------------------------------------------------
    */

    public function test_any_staff_member_may_raise_a_ticket(): void
    {
        // Whoever hits the problem is who needs to report it, so this is not a
        // manager-only action.
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->postJson('/api/support', [
            'subject' => 'الطابعة لا تستجيب',
            'body' => 'الطابعة في الفرع الرئيسي توقفت عن العمل منذ الصباح.',
            'priority' => SupportPriorityEnum::High->value,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', SupportTicketStatusEnum::Open->value)
            ->assertJsonPath('data.priority', SupportPriorityEnum::High->value)
            ->assertJsonCount(1, 'data.messages')
            ->assertJsonPath('data.messages.0.author_type', 'tenant');
    }

    public function test_a_ticket_defaults_to_normal_priority(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch));

        $this->postJson('/api/support', ['subject' => 'سؤال', 'body' => 'استفسار بسيط'])
            ->assertCreated()
            ->assertJsonPath('data.priority', SupportPriorityEnum::Normal->value);
    }

    public function test_the_configured_acknowledgement_is_posted_with_no_author(): void
    {
        $this->configureSupport([
            'autoReplyEnabled' => true,
            'autoReplyText' => 'وصلتنا رسالتك، هنرد عليك قريب.',
        ]);

        $this->actingAsStaff($this->createStaff($this->branch));

        $response = $this->postJson('/api/support', ['subject' => 'سؤال', 'body' => 'استفسار'])
            ->assertCreated()
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.messages.1.author_type', 'admin');

        // Nobody wrote it, so attributing it to an admin would be a lie in the thread.
        $this->assertNull($response->json('data.messages.1.author'));
    }

    public function test_a_blank_acknowledgement_is_not_posted(): void
    {
        $this->configureSupport(['autoReplyEnabled' => true, 'autoReplyText' => '   ']);
        $this->actingAsStaff($this->createStaff($this->branch));

        $this->postJson('/api/support', ['subject' => 'سؤال', 'body' => 'استفسار'])
            ->assertCreated()
            ->assertJsonCount(1, 'data.messages');
    }

    public function test_the_category_must_be_one_the_operator_configured(): void
    {
        $this->configureSupport(['categories' => ['billing', 'technical']]);
        $this->actingAsStaff($this->createStaff($this->branch));

        $this->postJson('/api/support', ['subject' => 'سؤال', 'body' => 'التفاصيل', 'category' => 'billing'])
            ->assertCreated()
            ->assertJsonPath('data.category', 'billing');

        $this->postJson('/api/support', ['subject' => 'سؤال', 'body' => 'التفاصيل', 'category' => 'invented'])
            ->assertStatus(422);
    }

    public function test_the_listing_carries_the_configured_categories(): void
    {
        $this->configureSupport(['categories' => ['billing']]);
        $this->actingAsStaff($this->createStaff($this->branch));

        $this->getJson('/api/support')
            ->assertOk()
            ->assertJsonPath('data.categories', ['billing']);
    }

    public function test_a_laundry_only_sees_its_own_tickets(): void
    {
        $mine = $this->ticketFor($this->organization);
        $theirs = $this->ticketFor($this->createOrganization());

        $this->actingAsStaff($this->createStaff($this->branch));

        $this->getJson('/api/support')
            ->assertOk()
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.data.data.0.id', $mine->getKey());

        $this->getJson("/api/support/{$theirs->getKey()}")->assertNotFound();
        $this->postJson("/api/support/{$theirs->getKey()}/reply", ['body' => 'hello'])->assertNotFound();
    }

    public function test_a_tenants_reply_reopens_a_resolved_ticket(): void
    {
        $ticket = $this->ticketFor($this->organization, SupportTicketStatusEnum::Resolved);
        $this->actingAsStaff($this->createStaff($this->branch));

        // Someone still needs help, whatever the operator had marked it.
        $this->postJson("/api/support/{$ticket->getKey()}/reply", ['body' => 'ما زالت المشكلة قائمة'])
            ->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatusEnum::Open->value);
    }

    /*
    |--------------------------------------------------------------------------
    | The operator's side
    |--------------------------------------------------------------------------
    */

    public function test_the_inbox_shows_every_tenants_tickets_with_counts(): void
    {
        $this->ticketFor($this->organization);
        $this->ticketFor($this->createOrganization(), SupportTicketStatusEnum::Closed);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/support')
            ->assertOk()
            ->assertJsonPath('data.data.total', 2)
            ->assertJsonPath('data.counts.total', 2)
            ->assertJsonPath('data.counts.open', 1)
            ->assertJsonPath('data.counts.closed', 1)
            // Every status is present even at zero, so the tabs do not flicker.
            ->assertJsonPath('data.counts.pending', 0);
    }

    public function test_an_admins_reply_puts_the_ticket_back_on_the_tenant(): void
    {
        $ticket = $this->openedTicket();
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/support/{$ticket->getKey()}/reply", ['body' => 'جارٍ الفحص'])
            ->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatusEnum::Pending->value)
            ->assertJsonPath('data.awaiting_us', false);
    }

    public function test_a_closed_ticket_is_not_dragged_back_by_a_closing_note(): void
    {
        $ticket = $this->ticketFor($this->organization, SupportTicketStatusEnum::Closed);
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/support/{$ticket->getKey()}/reply", ['body' => 'للتوثيق'])
            ->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatusEnum::Closed->value);
    }

    public function test_a_ticket_waiting_on_us_past_the_promise_is_flagged(): void
    {
        $this->configureSupport(['slaResponseMinutes' => 60]);
        $ticket = $this->openedTicket();

        // Backdate the tenant's message past the promised window.
        $ticket->messages()->update(['created_at' => Carbon::now()->subHours(3)]);

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/admin/support/{$ticket->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.awaiting_us', true)
            ->assertJsonPath('data.sla_breached', true);
    }

    public function test_a_ticket_waiting_on_the_tenant_never_breaches(): void
    {
        $this->configureSupport(['slaResponseMinutes' => 1]);
        $ticket = $this->openedTicket();

        Sanctum::actingAs($this->owner());
        $this->postJson("/api/admin/support/{$ticket->getKey()}/reply", ['body' => 'رد'])->assertOk();

        $ticket->messages()->update(['created_at' => Carbon::now()->subDay()]);

        // The ball is theirs; the wait is not ours to answer for.
        $this->getJson("/api/admin/support/{$ticket->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.sla_breached', false);
    }

    public function test_triage_sets_status_priority_and_owner(): void
    {
        $ticket = $this->openedTicket();
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $this->putJson("/api/admin/support/{$ticket->getKey()}", [
            'status' => SupportTicketStatusEnum::Resolved->value,
            'priority' => SupportPriorityEnum::Urgent->value,
            'assigned_to_id' => $owner->getKey(),
        ])
            ->assertOk()
            ->assertJsonPath('data.status', SupportTicketStatusEnum::Resolved->value)
            ->assertJsonPath('data.priority', SupportPriorityEnum::Urgent->value)
            ->assertJsonPath('data.assigned_to_id', $owner->getKey());

        // An explicit null hands it back to the queue rather than reading as "unchanged".
        $this->putJson("/api/admin/support/{$ticket->getKey()}", ['assigned_to_id' => null])
            ->assertOk()
            ->assertJsonPath('data.assigned_to_id', null)
            // The rest of the triage stands.
            ->assertJsonPath('data.priority', SupportPriorityEnum::Urgent->value);
    }

    public function test_a_ticket_cannot_be_assigned_to_someone_who_is_not_a_platform_admin(): void
    {
        $ticket = $this->openedTicket();
        $staff = $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);

        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/support/{$ticket->getKey()}", ['assigned_to_id' => $staff->getKey()])
            ->assertStatus(422);
    }

    public function test_reading_the_queue_is_open_to_any_admin_but_answering_is_not(): void
    {
        $ticket = $this->openedTicket();
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/admin/support')->assertOk();
        $this->getJson("/api/admin/support/{$ticket->getKey()}")->assertOk();

        $this->postJson("/api/admin/support/{$ticket->getKey()}/reply", ['body' => 'رد'])->assertStatus(403);
        $this->putJson("/api/admin/support/{$ticket->getKey()}", ['status' => 'closed'])->assertStatus(403);
    }

    public function test_a_support_agent_may_answer(): void
    {
        $ticket = $this->openedTicket();
        Sanctum::actingAs($this->supportAgent());

        $this->postJson("/api/admin/support/{$ticket->getKey()}/reply", ['body' => 'رد'])->assertOk();
    }

    public function test_a_tenant_staff_member_cannot_reach_the_operators_inbox(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/admin/support')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $values
     */
    private function configureSupport(array $values): void
    {
        app(PlatformSettingsService::class)->save('support', $values);
    }

    private function ticketFor(
        Organization $organization,
        SupportTicketStatusEnum $status = SupportTicketStatusEnum::Open,
    ): SupportTicket {
        return SupportTicket::factory()->status($status)->create([
            'organization_id' => $organization->getKey(),
        ]);
    }

    /**
     * A ticket raised through the API, so it has a real tenant message to reason about.
     */
    private function openedTicket(): SupportTicket
    {
        $this->actingAsStaff($this->createStaff($this->branch));

        $id = $this->postJson('/api/support', ['subject' => 'مشكلة', 'body' => 'التفاصيل'])
            ->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();

        return SupportTicket::query()->findOrFail($id);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }

    /**
     * A platform admin with no grants at all.
     */
    private function viewer(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();

        return $user;
    }

    private function supportAgent(): User
    {
        $user = $this->viewer();
        $user->forceFill(['platform_role' => PlatformRoleEnum::Support->value])->save();
        $user->platformPermissions()->create(['permission' => PlatformPermissionEnum::ManageSupport->value]);

        return $user;
    }
}
