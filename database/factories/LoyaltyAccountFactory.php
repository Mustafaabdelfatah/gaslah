<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

class LoyaltyAccountFactory extends Factory
{
    protected $model = LoyaltyAccount::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'program_id' => LoyaltyProgram::factory(),
            'points_balance' => 100,
            'lifetime_points' => 100,
        ];
    }
}
