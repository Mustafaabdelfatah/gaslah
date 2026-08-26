<?php

namespace App\Http\Resources\Tenancy\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The payload returned after a platform administrator signs in.
 *
 * @property-read array{user: User, token: string, permissions: array<int, string>} $resource
 */
class PlatformSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource['user'];

        return [
            'token' => $this->resource['token'],
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'platform_role' => $user->effectivePlatformRole()?->value,
            ],
            'permissions' => $this->resource['permissions'],
        ];
    }
}
