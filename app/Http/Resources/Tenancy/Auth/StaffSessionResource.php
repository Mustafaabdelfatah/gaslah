<?php

namespace App\Http\Resources\Tenancy\Auth;

use App\Models\User;
use App\Services\Tenancy\EntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The payload returned after a staff member signs in: the token, who they are, the
 * permissions in force, and a snapshot of what their organization is entitled to.
 *
 * @property-read array{user: User, token: string, organization_id: int, branch_id: int, permissions: array<int, string>} $resource
 */
class StaffSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];
        $organization = $user->branches()->first()?->organization;

        return [
            'token' => $this->resource['token'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'avatar' => $user->avatar,
                'role' => $user->role?->value,
            ],
            'organization' => $organization === null ? null : [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'branch_id' => $this->resource['branch_id'],
            'permissions' => $this->resource['permissions'],
            'entitlements' => $organization === null
                ? null
                : app(EntitlementService::class)->snapshot($organization),
        ];
    }
}
