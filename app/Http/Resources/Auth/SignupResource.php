<?php

namespace App\Http\Resources\Auth;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The result of a successful self-service signup: the provisioned tenant and a staff
 * session token, so the new owner lands logged in.
 */
class SignupResource extends JsonResource
{
    public function __construct(
        User $resource,
        private readonly Organization $organization,
        private readonly string $token,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'token' => $this->token,
            'user' => [
                'id' => $this->id,
                'name' => $this->name,
                'email' => $this->email,
            ],
            'organization' => [
                'id' => $this->organization->getKey(),
                'name' => $this->organization->name,
                'slug' => $this->organization->slug,
            ],
        ];
    }
}
