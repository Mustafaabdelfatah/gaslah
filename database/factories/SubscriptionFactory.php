<?php

namespace Database\Factories;

use App\Enum\Subscriptions\SubscriptionStatusEnum;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'customer_id' => Customer::factory(),
            'plan_id' => SubscriptionPlan::factory(),
            'branch_id' => null,
            'start_at' => now(),
            'end_at' => now()->addMonth(),
            'status' => SubscriptionStatusEnum::Active->value,
            'remaining_quota' => 30,
            'remaining_balance' => null,
            'auto_renew' => true,
        ];
    }

    public function expired(): self
    {
        return $this->state(['end_at' => now()->subDay()]);
    }

    public function frozen(): self
    {
        return $this->state(['status' => SubscriptionStatusEnum::Frozen->value]);
    }
}
