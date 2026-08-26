<?php

namespace Database\Factories;

use App\Enum\Orders\OrderPriorityEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        $organization = Organization::factory();

        return [
            'organization_id' => $organization,
            'branch_id' => Branch::factory(),
            'customer_id' => Customer::factory(),
            'order_no' => 'BR-'.now()->format('Ymd').'-'.$this->faker->unique()->numerify('####'),
            'barcode' => strtoupper(Str::random(16)),
            'status' => OrderStatusEnum::Received->value,
            'priority' => OrderPriorityEnum::Normal->value,
            'payment_status' => PaymentStatusEnum::Unpaid->value,
            'subtotal' => 100,
            'tax_total' => 15,
            'tax_rate' => 15,
            'grand_total' => 115,
            'paid_total' => 0,
        ];
    }
}
