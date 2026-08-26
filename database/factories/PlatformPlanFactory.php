<?php

namespace Database\Factories;

use App\Models\PlatformPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformPlanFactory extends Factory
{
    protected $model = PlatformPlan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['Starter', 'Pro', 'Enterprise']),
            'monthly_price' => 200,
            'yearly_price' => 2000,
            'max_branches' => 3,
            'max_users' => 10,
            'feature_keys' => ['delivery', 'loyalty'],
            'is_active' => true,
            'sort_order' => 1,
        ];
    }
}
