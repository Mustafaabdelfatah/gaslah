<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerAddressFactory extends Factory
{
    protected $model = CustomerAddress::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'label' => $this->faker->randomElement(['Home', 'Work']),
            'district' => $this->faker->city(),
            'street' => $this->faker->streetName(),
            'is_default' => false,
        ];
    }
}
