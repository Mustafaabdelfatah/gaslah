<?php

namespace App\Services\Platform;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Models\UserBranch;
use App\Services\Tenancy\StaffPermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shared tenant-provisioning path: an organization, its main branch, a super-admin
 * user, and a free trial — created atomically. Reused by public signup, admin tenant
 * creation, and lead conversion.
 */
class TenantProvisioner
{
    public function __construct(
        private readonly StaffPermissionService $permissions,
        private readonly PlatformSubscriptionService $subscriptions,
    ) {}

    /**
     * @param  array{org_name: string, admin_name: string, email: string, password: string, phone?: string|null, plan_id?: int|null}  $attributes
     * @return array{organization: Organization, branch: Branch, user: User}
     */
    public function provision(array $attributes): array
    {
        return DB::transaction(function () use ($attributes) {
            $organization = Organization::query()->create([
                'name' => $attributes['org_name'],
                'slug' => $this->uniqueSlug($attributes['org_name']),
                'default_currency' => 'SAR',
                'tax_rate' => 15,
            ]);

            $branch = Branch::query()->create([
                'organization_id' => $organization->getKey(),
                'name' => 'الفرع الرئيسي',
                'code' => 'MAIN',
                'is_active' => true,
            ]);

            $user = User::query()->create([
                'name' => $attributes['admin_name'],
                'email' => $attributes['email'],
                'phone' => $attributes['phone'] ?? null,
                'password' => $attributes['password'],
            ]);

            UserBranch::query()->create([
                'user_id' => $user->getKey(),
                'branch_id' => $branch->getKey(),
                'role' => StaffRoleEnum::SuperAdmin->value,
            ]);
            $this->permissions->syncDerivedRole($user);

            $plan = isset($attributes['plan_id']) ? PlatformPlan::query()->find($attributes['plan_id']) : null;
            if ($plan !== null || PlatformPlan::query()->where('is_active', true)->exists()) {
                $this->subscriptions->startTrial($organization, $plan);
            }

            return ['organization' => $organization, 'branch' => $branch, 'user' => $user];
        });
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'org';

        do {
            $slug = $base.'-'.Str::lower(Str::random(6));
        } while (Organization::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
