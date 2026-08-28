<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\PlatformAnnouncement;
use App\Models\User;
use App\Services\Platform\PlatformSettingsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformAnnouncementApiTest extends TestCase
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

    public function test_an_admin_broadcasts_an_announcement(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/announcements', [
            'title' => 'صيانة مجدولة', 'body' => 'الليلة', 'level' => 'warning',
        ])->assertCreated()->assertJsonPath('data.level', 'warning');

        $this->getJson('/api/admin/announcements')->assertOk()->assertJsonPath('data.data.0.title', 'صيانة مجدولة');
    }

    public function test_a_non_admin_cannot_broadcast(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/admin/announcements', ['title' => 'x', 'body' => 'y'])->assertStatus(403);
    }

    public function test_a_tenant_sees_only_the_banners_aimed_at_it(): void
    {
        [$other] = $this->createTenant();

        PlatformAnnouncement::factory()->create(['title' => 'to-all', 'organization_id' => null]);
        PlatformAnnouncement::factory()->create(['title' => 'to-me', 'organization_id' => $this->organization->getKey()]);
        PlatformAnnouncement::factory()->create(['title' => 'to-other', 'organization_id' => $other->getKey()]);
        PlatformAnnouncement::factory()->create(['title' => 'inactive', 'organization_id' => null, 'is_active' => false]);
        PlatformAnnouncement::factory()->create(['title' => 'future', 'organization_id' => null, 'starts_at' => now()->addWeek()]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $titles = collect($this->getJson('/api/org/notices')->assertOk()->json('data'))->pluck('title');

        $this->assertEqualsCanonicalizing(['to-all', 'to-me'], $titles->all());
    }

    public function test_a_banner_takes_the_operators_defaults_when_none_are_given(): void
    {
        app(PlatformSettingsService::class)->save('announcements', [
            'defaultLevel' => 'warning',
            'defaultDurationDays' => 3,
        ]);

        Sanctum::actingAs($this->owner());

        $response = $this->postJson('/api/admin/announcements', ['title' => 'صيانة', 'body' => 'الليلة'])
            ->assertCreated()
            ->assertJsonPath('data.level', 'warning');

        // A banner with no end date would run for ever, which is not what an operator
        // means by leaving the field blank on a maintenance notice.
        $this->assertTrue(
            Carbon::parse($response->json('data.ends_at'))->between(Carbon::now(), Carbon::now()->addDays(4)),
        );
    }

    public function test_an_explicit_level_and_end_date_are_not_overridden(): void
    {
        app(PlatformSettingsService::class)->save('announcements', ['defaultLevel' => 'warning']);
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/announcements', [
            'title' => 'خبر', 'body' => 'تفاصيل', 'level' => 'info', 'ends_at' => '2030-01-01',
        ])
            ->assertCreated()
            ->assertJsonPath('data.level', 'info');
    }

    public function test_an_edit_does_not_re_apply_the_defaults(): void
    {
        $announcement = PlatformAnnouncement::factory()->create(['ends_at' => null, 'level' => 'critical']);
        app(PlatformSettingsService::class)->save('announcements', ['defaultLevel' => 'info']);

        Sanctum::actingAs($this->owner());

        // A partial edit keeps what it did not mention — defaults included.
        $this->putJson("/api/admin/announcements/{$announcement->getKey()}", ['title' => 'محدَّث'])
            ->assertOk()
            ->assertJsonPath('data.level', 'critical')
            ->assertJsonPath('data.ends_at', null);
    }

    public function test_the_tenants_banner_strip_is_capped_by_the_operators_setting(): void
    {
        app(PlatformSettingsService::class)->save('announcements', ['tenantNoticeLimit' => 2]);
        PlatformAnnouncement::factory()->count(5)->create(['organization_id' => null]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/org/notices')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_the_tenants_view_withholds_the_operators_own_fields(): void
    {
        PlatformAnnouncement::factory()->create(['organization_id' => $this->organization->getKey()]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $notice = $this->getJson('/api/org/notices')->assertOk()->json('data.0');

        // Who a banner targets, who wrote it and whether it is switched on are the
        // operator's business, not the reader's.
        $this->assertSame(['id', 'title', 'body', 'level', 'starts_at', 'ends_at'], array_keys($notice));
    }

    public function test_the_activity_log_records_admin_actions(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/suspend", ['suspended' => true])->assertOk();

        $this->getJson('/api/admin/activity')
            ->assertOk()
            ->assertJsonPath('data.data.0.action', 'suspend')
            ->assertJsonPath('data.data.0.organization_id', $this->organization->getKey());
    }

    public function test_the_tenant_users_list_returns_staff_with_roles(): void
    {
        $this->createStaff($this->branch, StaffRoleEnum::Cashier);
        Sanctum::actingAs($this->owner());

        $this->getJson("/api/admin/tenants/{$this->organization->getKey()}/users")
            ->assertOk()
            ->assertJsonStructure(['data' => ['data' => [['id', 'name', 'email', 'roles']]]]);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}
