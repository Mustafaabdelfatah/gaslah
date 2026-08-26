<?php

namespace Tests\Feature\Tenancy\Auth;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
    }

    public function test_a_staff_member_signs_in_and_receives_a_scoped_token(): void
    {
        [$organization, $branch] = $this->createTenant();
        $user = $this->createStaff($branch, StaffRoleEnum::Cashier, ['password' => 'Secret123']);

        $response = $this->postJson('/api/staff/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ]);

        $response->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.organization.id', $organization->getKey())
            ->assertJsonPath('data.branch_id', $branch->getKey())
            ->assertJsonPath('data.user.role', StaffRoleEnum::Cashier->value)
            ->assertJsonPath('data.entitlements.active', true);

        $this->assertContains('pos.checkout', $response->json('data.permissions'));
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_the_token_carries_the_organization_and_branch_scope(): void
    {
        [$organization, $branch] = $this->createTenant();
        $user = $this->createStaff($branch, StaffRoleEnum::Cashier, ['password' => 'Secret123']);

        $this->postJson('/api/staff/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertOk();

        // The scope is stamped on the token so the guard can re-verify membership
        // and pin writes on every later request.
        $meta = $user->tokens()->latest('id')->first()->meta;

        $this->assertSame('staff', $meta['kind']);
        $this->assertSame($organization->getKey(), $meta['organization_id']);
        $this->assertSame($branch->getKey(), $meta['branch_id']);
    }

    public function test_a_wrong_password_is_refused_with_a_uniform_message(): void
    {
        [, $branch] = $this->createTenant();
        $user = $this->createStaff($branch, attributes: ['password' => 'Secret123']);

        $wrongPassword = $this->postJson('/api/staff/login', [
            'email' => $user->email,
            'password' => 'nope',
        ]);
        $unknownEmail = $this->postJson('/api/staff/login', [
            'email' => 'ghost@example.com',
            'password' => 'nope',
        ]);

        $wrongPassword->assertStatus(401);
        $unknownEmail->assertStatus(401);

        // The same message for both, so the response cannot enumerate accounts.
        $this->assertSame($unknownEmail->json('message'), $wrongPassword->json('message'));
    }

    public function test_a_disabled_account_cannot_sign_in(): void
    {
        [, $branch] = $this->createTenant();
        $user = $this->createStaff($branch, attributes: ['password' => 'Secret123', 'is_active' => false]);

        $this->postJson('/api/staff/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertStatus(401);
    }

    public function test_an_account_with_no_organization_is_refused(): void
    {
        $user = $this->createUser(['password' => 'Secret123']);

        $this->postJson('/api/staff/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertStatus(403);
    }

    public function test_a_platform_token_cannot_reach_a_staff_endpoint(): void
    {
        [, $branch] = $this->createTenant();
        $staff = $this->createStaff($branch, StaffRoleEnum::SuperAdmin);

        $platformUser = $this->createUser();
        $platformUser->forceFill(['is_platform_owner' => true])->save();
        $token = $platformUser->createToken('platform')->plainTextToken;

        // A platform account has no branch membership, so any tenant-scoped route
        // refuses it once it tries to resolve an organization.
        $this->withToken($token)
            ->getJson('/api/staff/context')
            ->assertStatus(403);
    }

    public function test_repeated_failures_lock_the_address_out(): void
    {
        config()->set('project.auth.lockout.max_attempts', 3);
        [, $branch] = $this->createTenant();
        $user = $this->createStaff($branch, attributes: ['password' => 'Secret123']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/api/staff/login', [
                'email' => $user->email,
                'password' => 'wrong',
            ])->assertStatus(401);
        }

        // The next attempt is refused before the password is even checked — a correct
        // password does not get through while locked.
        $this->postJson('/api/staff/login', [
            'email' => $user->email,
            'password' => 'Secret123',
        ])->assertStatus(429);
    }

    public function test_a_successful_sign_in_clears_the_failure_count(): void
    {
        config()->set('project.auth.lockout.max_attempts', 3);
        [, $branch] = $this->createTenant();
        $user = $this->createStaff($branch, attributes: ['password' => 'Secret123']);

        $this->postJson('/api/staff/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/staff/login', ['email' => $user->email, 'password' => 'Secret123'])->assertOk();

        // Failures before the success are wiped, so they cannot accumulate toward a
        // lockout across a good sign-in.
        $this->postJson('/api/staff/login', ['email' => $user->email, 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/staff/login', ['email' => $user->email, 'password' => 'Secret123'])->assertOk();
    }
}
