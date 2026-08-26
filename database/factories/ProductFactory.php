<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Product;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'category_id' => ServiceCategory::factory(),
            'name' => $this->faker->word(),
            'is_active' => true,
        ];
    }
}
