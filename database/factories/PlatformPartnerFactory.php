<?php

namespace Database\Factories;

use App\Models\PlatformPartner;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformPartnerFactory extends Factory
{
    protected $model = PlatformPartner::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'role' => 'مؤسس',
            'ownership_percent' => 25,
            'joined_at' => now()->subYear()->toDateString(),
            'is_active' => true,
        ];
    }
}
