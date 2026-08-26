<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class UnitFactory extends Factory
{
    protected $model = Unit::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->randomElement(['Piece', 'Litre', 'Kilogram']),
            'symbol' => $this->faker->randomElement(['pc', 'L', 'kg']),
            'conversion_factor' => 1,
            'is_active' => true,
        ];
    }
}
