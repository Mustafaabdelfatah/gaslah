<?php

namespace Database\Factories;

use App\Enum\Catalog\CustomerTypeEnum;
use App\Models\Customer;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->name(),
            'phone' => '05'.$this->faker->unique()->numerify('########'),
            'type' => CustomerTypeEnum::Regular->value,
        ];
    }
}
