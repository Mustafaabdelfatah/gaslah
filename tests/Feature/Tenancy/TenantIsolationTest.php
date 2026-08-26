<?php

namespace Tests\Feature\Tenancy;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_is_derived_from_branch_membership(): void
    {
        [$organization, $branch] = $this->createTenant();
        $user = $this->createStaff($branch);

        $context = $this->contextFor($user);

        $this->assertSame($organization->getKey(), $context->organizationId());
        $this->assertSame($branch->getKey(), $context->writeBranchId());
    }

    public function test_account_without_membership_has_no_organization(): void
    {
        $user = $this->createUser();

        $context = $this->contextFor($user);

        $this->assertNull($context->organizationId());
        $this->assertFalse($context->hasOrganization());
        $this->assertSame([], $context->readBranchIds());
    }

    public function test_requiring_an_organization_refuses_a_detached_account(): void
    {
        $user = $this->createUser();
        $context = $this->contextFor($user);

        $this->assertAborts(403, fn () => $context->requireOrganizationId());
    }

    public function test_reads_default_to_every_branch_of_the_organization(): void
    {
        [$organization, $main] = $this->createTenant();
        $second = Branch::factory()->create(['organization_id' => $organization->getKey()]);

        // Membership in one branch still grants visibility across the organization.
        $user = $this->createStaff($main, StaffRoleEnum::BranchManager);

        $context = $this->contextFor($user);

        $this->assertEqualsCanonicalizing(
            [$main->getKey(), $second->getKey()],
            $context->readBranchIds()
        );
    }

    public function test_branch_header_narrows_reads_to_a_single_branch(): void
    {
        [$organization, $main] = $this->createTenant();
        $second = Branch::factory()->create(['organization_id' => $organization->getKey()]);
        $user = $this->createStaff($main);

        $context = $this->contextFor($user, $second->getKey());

        $this->assertSame([$second->getKey()], $context->readBranchIds());
    }

    public function test_branch_header_naming_another_tenant_is_ignored(): void
    {
        [, $main] = $this->createTenant();
        [, $foreignBranch] = $this->createTenant();
        $user = $this->createStaff($main);

        $context = $this->contextFor($user, $foreignBranch->getKey());

        // The header may only narrow. Pointing it at another tenant leaves the
        // caller with their own organization rather than reaching across.
        $this->assertSame([$main->getKey()], $context->readBranchIds());
        $this->assertNotContains($foreignBranch->getKey(), $context->readBranchIds());
    }

    public function test_branch_header_does_not_move_where_writes_land(): void
    {
        [$organization, $main] = $this->createTenant();
        $second = Branch::factory()->create(['organization_id' => $organization->getKey()]);
        $user = $this->createStaff($main);

        $context = $this->contextFor($user, $second->getKey());

        $this->assertSame([$second->getKey()], $context->readBranchIds());
        $this->assertSame($main->getKey(), $context->writeBranchId(), 'Filtering a listing must not relocate writes.');
    }

    public function test_write_branch_stays_pinned_to_the_branch_signed_in_at(): void
    {
        [$organization, $main] = $this->createTenant();
        $second = Branch::factory()->create(['organization_id' => $organization->getKey()]);

        $user = $this->createStaff($main, StaffRoleEnum::BranchManager);
        $this->attachToBranch($user, $second, StaffRoleEnum::BranchManager);

        $this->pinTokenToBranch($user, $second);

        // Covering two branches must not make writes drift between requests.
        $this->assertSame($second->getKey(), $this->contextFor($user)->writeBranchId());
    }

    public function test_write_branch_falls_back_when_the_pinned_membership_is_gone(): void
    {
        [$organization, $main] = $this->createTenant();
        $second = Branch::factory()->create(['organization_id' => $organization->getKey()]);

        $user = $this->createStaff($main);
        $this->attachToBranch($user, $second, StaffRoleEnum::Cashier);
        $this->pinTokenToBranch($user, $second);

        $user->userBranches()->where('branch_id', $second->getKey())->delete();

        $this->assertSame($main->getKey(), $this->contextFor($user)->writeBranchId());
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function contextFor(User $user, ?int $branchHeader = null): TenantContext
    {
        $server = $branchHeader === null
            ? []
            : ['HTTP_X_BRANCH_ID' => (string) $branchHeader];

        return (new TenantContext(Request::create('/', 'GET', server: $server)))->forUser($user);
    }

    private function pinTokenToBranch(User $user, Branch $branch): void
    {
        $accessToken = $user->createToken('api')->accessToken;
        $accessToken->forceFill(['meta' => ['branch_id' => $branch->getKey()]])->save();

        $user->withAccessToken($accessToken);
    }
}
