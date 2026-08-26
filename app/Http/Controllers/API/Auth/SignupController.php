<?php

namespace App\Http\Controllers\API\Auth;

use App\Enum\Global\TokenKindEnum;
use App\Http\Controllers\API\BaseController;
use App\Models\Branch;
use App\Models\User;
use App\Services\Platform\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public self-service signup: provisions a tenant (org + main branch + super-admin + trial)
 * and returns a staff session token. Rate-limited, and gated by the platform's public-signup
 * switch — checked before validation so a closed signup cannot be used to probe email
 * existence via the unique rule.
 */
class SignupController extends BaseController
{
    public function __construct(private readonly TenantProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless((bool) config('services.platform.allow_public_signup', true), 403, __('api.signup_closed'));

        $data = $request->validate([
            'org_name' => ['required', 'string', 'min:2', 'max:200'],
            'admin_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
        ]);

        $result = $this->provisioner->provision($data);

        return successResponse([
            'token' => $this->issueToken($result['user'], $result['branch']),
            'user' => $result['user']->only('id', 'name', 'email'),
            'organization' => $result['organization']->only('id', 'name', 'slug'),
        ], __('api.created_success'), 201);
    }

    private function issueToken(User $user, Branch $branch): string
    {
        $token = $user->createToken(TokenKindEnum::Staff->value);

        $token->accessToken->forceFill([
            'meta' => [
                'kind' => TokenKindEnum::Staff->value,
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->getKey(),
            ],
        ])->save();

        return $token->plainTextToken;
    }
}
