<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthApiTest extends TestCase
{
    public function test_the_connectivity_probe_is_small_and_public(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertExactJson([
                'status' => true,
                'code' => 200,
                'message' => __('api.success'),
                'data' => ['reachable' => true],
            ]);
    }
}
