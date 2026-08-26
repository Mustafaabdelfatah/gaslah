<?php

namespace Tests\Feature\Tenancy\Auth;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_platform_admin_signs_in_and_receives_a_platform_token(): void
    {
        $owner = $this->platformUser(PlatformRoleEnum::Owner, 'Secret123');

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => $owner->email,
            'password' => 'Secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.user.platform_role', PlatformRoleEnum::Owner->value);

        $meta = $owner->tokens()->latest('id')->first()->meta;
        $this->assertSame('platform', $meta['kind']);

        // A platform token has no tenant scope of its own.
        $this->assertArrayNotHasKey('organization_id', $meta);
    }

    public function test_a_tenant_account_cannot_sign_in_to_the_platform_console(): void
    {
        [, $branch] = $this->createTenant();
        $staff = $this->createStaff($branch, StaffRoleEnum::SuperAdmin, ['password' => 'Secret123']);

        // Correct credentials are not enough: without the operator flag the console
        // stays closed.
        $this->postJson('/api/platform/auth/login', [
            'email' => $staff->email,
            'password' => 'Secret123',
        ])->assertStatus(403);
    }

    public function test_a_narrow_platform_role_reports_only_its_permissions(): void
    {
        $finance = $this->platformUser(PlatformRoleEnum::Finance, 'Secret123');
        $finance->platformPermissions()->create(['permission' => 'view_finance']);
        $finance->platformPermissions()->create(['permission' => 'manage_payouts']);

        $response = $this->postJson('/api/platform/auth/login', [
            'email' => $finance->email,
            'password' => 'Secret123',
        ]);

        $response->assertOk();
        $permissions = $response->json('data.permissions');

        $this->assertContains('manage_payouts', $permissions);
        $this->assertNotContains('manage_tenants', $permissions);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $owner = $this->platformUser(PlatformRoleEnum::Owner, 'Secret123');

        $this->postJson('/api/platform/auth/login', [
            'email' => $owner->email,
            'password' => 'wrong',
        ])->assertStatus(401);
    }

    private function platformUser(PlatformRoleEnum $role, string $password): User
    {
        $user = $this->createUser(['password' => $password]);
        $user->forceFill([
            'is_platform_owner' => true,
            'platform_role' => $role->value,
        ])->save();

        return $user;
    }
}
