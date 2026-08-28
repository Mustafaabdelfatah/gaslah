<?php

namespace Database\Factories;

use App\Enum\Market\MarketCategoryEnum;
use App\Models\MarketProduct;
use App\Models\MarketSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketProductFactory extends Factory
{
    protected $model = MarketProduct::class;

    public function definition(): array
    {
        return [
            'supplier_id' => MarketSupplier::factory(),
            'name' => $this->faker->words(2, true),
            'category' => $this->faker->randomElement(MarketCategoryEnum::values()),
            'unit' => 'قطعة',
            'price' => $this->faker->randomFloat(2, 5, 500),
            'stock' => $this->faker->numberBetween(10, 500),
            'is_active' => true,
        ];
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
