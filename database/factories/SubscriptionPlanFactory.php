<?php

namespace Database\Factories;

use App\Enum\Subscriptions\SubscriptionCycleEnum;
use App\Enum\Subscriptions\SubscriptionTypeEnum;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionPlanFactory extends Factory
{
    protected $model = SubscriptionPlan::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => 'Package '.$this->faker->unique()->word(),
            'cycle' => SubscriptionCycleEnum::Monthly->value,
            'type' => SubscriptionTypeEnum::PieceQuota->value,
            'price' => 100,
            'quota' => 30,
            'auto_renew' => true,
            'is_active' => true,
        ];
    }

    public function pieceQuota(float $quota = 30): self
    {
        return $this->state(['type' => SubscriptionTypeEnum::PieceQuota->value, 'quota' => $quota]);
    }

    public function prepaidBalance(float $quota = 500): self
    {
        return $this->state(['type' => SubscriptionTypeEnum::PrepaidBalance->value, 'quota' => $quota]);
    }

    public function unlimited(): self
    {
        return $this->state(['type' => SubscriptionTypeEnum::UnlimitedService->value, 'quota' => null]);
    }

    public function free(): self
    {
        return $this->state(['price' => 0]);
    }
}
