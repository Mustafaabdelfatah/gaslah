<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SignupApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_signup_provisions_org_branch_admin_and_trial(): void
    {
        PlatformPlan::factory()->create(['monthly_price' => 200]);

        $response = $this->postJson('/api/signup', [
            'org_name' => 'مغسلة النقاء',
            'admin_name' => 'مالك المغسلة',
            'email' => 'owner@naqaa.test',
            'password' => 'secret123',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.organization.name', 'مغسلة النقاء')
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'email'], 'organization' => ['id', 'slug']]]);

        $org = Organization::query()->firstWhere('name', 'مغسلة النقاء');
        $this->assertNotNull($org);
        $this->assertNotNull($org->slug);

        $branch = Branch::query()->where('organization_id', $org->getKey())->firstWhere('code', 'MAIN');
        $this->assertNotNull($branch);

        $user = User::query()->firstWhere('email', 'owner@naqaa.test');
        $this->assertTrue($user->branches()->where('role', StaffRoleEnum::SuperAdmin->value)->exists());

        $subscription = PlatformSubscription::query()->firstWhere('organization_id', $org->getKey());
        $this->assertNotNull($subscription);
        $this->assertSame('trial', $subscription->status->value);
    }

    public function test_returned_token_authenticates_as_the_new_admin(): void
    {
        PlatformPlan::factory()->create();

        $token = $this->postJson('/api/signup', [
            'org_name' => 'مغسلة الصفا',
            'admin_name' => 'المالك',
            'email' => 'admin@safa.test',
            'password' => 'secret123',
        ])->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/org/entitlements')
            ->assertOk()
            ->assertJsonPath('data.status', 'trial');
    }

    public function test_duplicate_email_is_rejected(): void
    {
        User::factory()->create(['email' => 'taken@test.test']);

        $this->postJson('/api/signup', [
            'org_name' => 'مغسلة',
            'admin_name' => 'مالك',
            'email' => 'taken@test.test',
            'password' => 'secret123',
        ])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_signup_is_blocked_when_public_signup_is_closed(): void
    {
        config()->set('services.platform.allow_public_signup', false);

        $this->postJson('/api/signup', [
            'org_name' => 'مغسلة',
            'admin_name' => 'مالك',
            'email' => 'new@closed.test',
            'password' => 'secret123',
        ])->assertStatus(403);

        $this->assertNull(User::query()->firstWhere('email', 'new@closed.test'));
    }
}
