<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceCategoryFactory extends Factory
{
    protected $model = ServiceCategory::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->word(),
            'is_active' => true,
        ];
    }
}
