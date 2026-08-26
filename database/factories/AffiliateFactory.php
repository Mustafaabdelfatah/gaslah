<?php

namespace Database\Factories;

use App\Models\Affiliate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AffiliateFactory extends Factory
{
    protected $model = Affiliate::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => '05'.$this->faker->unique()->numerify('########'),
            'code' => Str::upper(Str::random(10)),
            'commission_type' => 'percent',
            'commission_rate' => 10,
            'is_active' => true,
        ];
    }
}
