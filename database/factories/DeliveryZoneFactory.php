<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\DeliveryZone;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryZoneFactory extends Factory
{
    protected $model = DeliveryZone::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'branch_id' => Branch::factory(),
            'name' => $this->faker->city(),
            'fee' => 15,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
