<?php

namespace Database\Factories;

use App\Models\LoyaltyProgram;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoyaltyProgramFactory extends Factory
{
    protected $model = LoyaltyProgram::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Loyalty',
            'earn_rate' => 1,
            'point_value' => 0.1,
            'expiry_months' => 12,
            'is_active' => true,
        ];
    }
}
