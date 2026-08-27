<?php

namespace Database\Factories;

use App\Models\PlatformDevice;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformDeviceFactory extends Factory
{
    protected $model = PlatformDevice::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->randomElement(['جهاز نقاط بيع', 'طابعة فواتير', 'قارئ باركود']),
            'sku' => strtoupper($this->faker->unique()->bothify('DEV-##??')),
            'price' => 1150,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
