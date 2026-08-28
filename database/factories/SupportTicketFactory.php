<?php

namespace Database\Factories;

use App\Enum\Support\SupportPriorityEnum;
use App\Enum\Support\SupportTicketStatusEnum;
use App\Models\Organization;
use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;

class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'subject' => $this->faker->sentence(4),
            'status' => SupportTicketStatusEnum::Open->value,
            'priority' => SupportPriorityEnum::Normal->value,
            'last_reply_at' => now(),
        ];
    }

    public function status(SupportTicketStatusEnum $status): self
    {
        return $this->state(['status' => $status->value]);
    }
}
