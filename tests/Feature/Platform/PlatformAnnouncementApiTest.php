<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\PlatformAnnouncement;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
