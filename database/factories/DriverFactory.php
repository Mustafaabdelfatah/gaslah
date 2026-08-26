<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Driver;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class DriverFactory extends Factory
{
    protected $model = Driver::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'branch_id' => Branch::factory(),
            'name' => $this->faker->name(),
            'phone' => '05'.$this->faker->unique()->numerify('########'),
            'is_active' => true,
            'is_platform' => false,
        ];
    }

    public function platform(): self
    {
        return $this->state(['is_platform' => true]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
