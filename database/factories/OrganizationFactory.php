<?php

namespace Database\Factories;

use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        $name = $this->faker->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 999999),
            'default_currency' => 'SAR',
            'tax_rate' => 15.00,
            'phone' => '0500000000',
            'email' => $this->faker->unique()->companyEmail(),
            'is_suspended' => false,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['is_suspended' => true]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
