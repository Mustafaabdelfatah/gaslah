<?php

namespace Database\Factories;

use App\Enum\Platform\PlatformCouponTypeEnum;
use App\Models\PlatformCoupon;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformCouponFactory extends Factory
{
    protected $model = PlatformCoupon::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper($this->faker->unique()->bothify('SAVE##??')),
            'type' => PlatformCouponTypeEnum::Percent->value,
            'value' => 10,
            'max_redemptions' => null,
            'redemptions' => 0,
            'is_active' => true,
        ];
    }
}
