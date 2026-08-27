<?php

namespace Tests\Feature\Tenancy;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Authorization must be decided before validation.
 *
 * When the guards lived inside the controllers, a form request validated first and someone
 * without permission was answered 422 with a list of field errors — telling them the shape
 * of a call they were never allowed to make, and reading as "fix your input" when the real
 * answer was "not you". Moving the guards onto the routes fixed that; these tests hold it
 * fixed, using deliberately invalid payloads so a 403 can only come from the guard.
 */
class AuthorizationOrderTest extends TestCase
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

    public function test_a_forbidden_write_is_refused_before_its_payload_is_validated(): void
    {
        // Reception may not touch the catalogue, the books, or the till.
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Reception));

        foreach ($this->forbiddenWrites() as $label => [$method, $uri]) {
            $this->json($method, $uri, ['name' => ''])
                ->assertStatus(403, "{$label} should refuse before validating");
        }
    }

    public function test_a_manager_is_still_refused_where_only_the_owner_may_act(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));

        // Reopening closed books and repointing the payout account are the owner's alone.
        $this->putJson('/api/accounting/period-lock', ['closed_through' => 'not-a-date'])->assertStatus(403);
        $this->patchJson('/api/payouts/config', ['days' => 'not-an-array'])->assertStatus(403);
        $this->putJson('/api/automation', ['enabled' => 'maybe'])->assertStatus(403);
    }

    public function test_a_permitted_caller_reaches_validation(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        // Same empty payload, but now the caller is allowed — so the answer is about the
        // payload, which is what proves the 403s above came from the guard and not the rules.
        $this->postJson('/api/catalog/categories', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    private function forbiddenWrites(): array
    {
        // Registering a customer is deliberately absent: reception holds customers.manage,
        // since taking someone's details at the counter is the job.
        return [
            'catalog category' => ['POST', '/api/catalog/categories'],
            'chart of accounts' => ['POST', '/api/accounting/accounts'],
            'bank reconciliation' => ['POST', '/api/bank/statement-balance'],
            'shift open' => ['POST', '/api/shifts/open'],
            'delivery settings' => ['PUT', '/api/delivery/settings'],
        ];
    }
}
