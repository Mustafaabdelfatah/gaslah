<?php

namespace Database\Factories;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Enum\Delivery\DeliveryTypeEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryRequest;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeliveryRequestFactory extends Factory
{
    protected $model = DeliveryRequest::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            'type' => DeliveryTypeEnum::Pickup->value,
            'status' => DeliveryStatusEnum::Requested->value,
            'fee' => 10,
            'address' => $this->faker->address(),
            'source' => DeliverySourceEnum::Staff->value,
        ];
    }

    public function delivery(): self
    {
        return $this->state(['type' => DeliveryTypeEnum::Delivery->value]);
    }
}
