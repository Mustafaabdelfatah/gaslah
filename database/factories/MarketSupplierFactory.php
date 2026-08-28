<?php

namespace Database\Factories;

use App\Enum\Market\MarketCommissionTypeEnum;
use App\Enum\Market\MarketSupplierStatusEnum;
use App\Models\MarketSupplier;
use Illuminate\Database\Eloquent\Factories\Factory;

class MarketSupplierFactory extends Factory
{
    protected $model = MarketSupplier::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'email' => $this->faker->unique()->safeEmail(),
            'phone' => '05'.$this->faker->unique()->numerify('########'),
            'password' => 'password',
            'status' => MarketSupplierStatusEnum::Approved->value,
            'city' => $this->faker->city(),
            'commission_type' => MarketCommissionTypeEnum::Percent->value,
            'commission_value' => 8,
            'approved_at' => now(),
        ];
    }

    public function pending(): self
    {
        return $this->state(['status' => MarketSupplierStatusEnum::Pending->value, 'approved_at' => null]);
    }

    public function suspended(): self
    {
        return $this->state(['status' => MarketSupplierStatusEnum::Suspended->value]);
    }

    public function rejected(): self
    {
        return $this->state(['status' => MarketSupplierStatusEnum::Rejected->value, 'approved_at' => null]);
    }

    /**
     * A supplier the platform charges a flat fee per order rather than a percentage.
     */
    public function fixedCommission(float $value): self
    {
        return $this->state([
            'commission_type' => MarketCommissionTypeEnum::Fixed->value,
            'commission_value' => $value,
        ]);
    }
}
