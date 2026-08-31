<?php

namespace App\Http\Resources\Tenancy;

use App\Services\Tenancy\PlatformAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A staff member of the organization.
 *
 * `permissions` is null when the account simply follows its role — which is not the
 * same as an empty list, meaning an override that grants nothing at all.
 */
class OrgUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,

            // The platform's own staff are visible but not a tenant's to edit.
            'is_platform_admin' => app(PlatformAccessService::class)->isPlatformAdmin($this->resource),

            'role' => $this->role,
            'branches' => $this->when(
                $this->resource->relationLoaded('userBranches'),
                fn () => $this->userBranches->map(fn ($membership) => [
                    'branch_id' => $membership->branch_id,
                    'branch_name' => $membership->branch?->name,
                    'role' => $membership->role,
                ])->values(),
            ),

            'permissions' => $this->when(
                $this->resource->relationLoaded('permissionOverride'),
                fn () => $this->permissionOverride === null
                    ? null
                    : $this->permissionOverride->items->map(fn ($item) => $item->permission)->values(),
            ),

            'created_at' => $this->created_at,
        ];
    }
}
