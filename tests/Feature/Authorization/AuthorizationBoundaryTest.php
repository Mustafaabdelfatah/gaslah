<?php

namespace Tests\Feature\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_endpoints_require_authentication(): void
    {
        $this->getJson('/api/staff/context')
            ->assertUnauthorized()
            ->assertJson([
                'status' => false,
                'code' => 401,
                'data' => [],
            ]);
    }
}
