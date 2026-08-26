<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class BranchFactory extends Factory
{
    protected $model = Branch::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->city(),
            'code' => strtoupper($this->faker->unique()->bothify('BR##??')),
            'phone' => '0500000000',
            'is_active' => true,
        ];
    }

    public function main(): static
    {
        return $this->state(fn () => ['code' => Branch::MAIN_CODE, 'name' => 'الفرع الرئيسي']);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
