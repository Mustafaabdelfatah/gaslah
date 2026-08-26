<?php

namespace Tests;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Country;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserBranch;
use App\Services\Tenancy\StaffPermissionService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

abstract class TestCase extends BaseTestCase
{
    protected function createCountry(array $attributes = []): Country
    {
        return Country::factory()->create($attributes);
    }

    protected function createUser(array $attributes = []): User
    {
        return User::factory()->create($attributes);
    }

    protected function actingAsUserWithPermissions(array $permissions = [], ?User $user = null): User
    {
        $user ??= $this->createUser();

        $this->givePermissions($user, $permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    protected function givePermissions(User $user, array $permissions): void
    {
        $permissionModels = [];

        foreach ($permissions as $permission) {
            $permissionModels[] = Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => config('roles.default_guard'),
            ], [
                'display_name' => [
                    'en' => $permission,
                    'ar' => $permission,
                ],
                'group' => 'testing',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        if ($permissions !== []) {
            $user->givePermissionTo($permissionModels);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /*
    |--------------------------------------------------------------------------
    | Tenancy helpers
    |--------------------------------------------------------------------------
    */
    protected function createOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    /**
     * An organization with its primary branch, the shape every tenant starts in.
     *
     * @return array{0: Organization, 1: Branch}
     */
    protected function createTenant(array $attributes = []): array
    {
        $organization = $this->createOrganization($attributes);

        $branch = Branch::factory()->main()->create([
            'organization_id' => $organization->getKey(),
        ]);

        return [$organization, $branch];
    }

    /**
     * A staff member holding the given role in the given branch.
     */
    protected function createStaff(
        Branch $branch,
        StaffRoleEnum $role = StaffRoleEnum::Cashier,
        array $attributes = []
    ): User {
        $user = $this->createUser($attributes);

        $this->attachToBranch($user, $branch, $role);

        return $user;
    }

    protected function attachToBranch(User $user, Branch $branch, StaffRoleEnum $role): UserBranch
    {
        $membership = UserBranch::query()->create([
            'user_id' => $user->getKey(),
            'branch_id' => $branch->getKey(),
            'role' => $role->value,
        ]);

        app(StaffPermissionService::class)->syncDerivedRole($user);

        return $membership;
    }

    /**
     * Bind the tenant context to a user without going through a real request.
     */
    protected function actingAsStaff(User $user): User
    {
        Sanctum::actingAs($user);
        app(TenantContext::class)->forUser($user);

        return $user;
    }

    /**
     * Assert the callback refuses the request with the given HTTP status.
     */
    protected function assertAborts(int $status, callable $callback): void
    {
        try {
            $callback();
        } catch (HttpExceptionInterface $exception) {
            $this->assertSame($status, $exception->getStatusCode());

            return;
        }

        $this->fail("Expected the call to be refused with status {$status}, but it succeeded.");
    }

    protected function assertSuccessEnvelope(TestResponse $response): void
    {
        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('code', 200);
    }
}
