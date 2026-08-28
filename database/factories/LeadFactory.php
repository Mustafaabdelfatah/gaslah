<?php

namespace Database\Factories;

use App\Enum\Crm\LeadStageEnum;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'business_name' => $this->faker->company(),
            'contact_name' => $this->faker->name(),
            'phone' => '05'.$this->faker->unique()->numerify('########'),
            'email' => $this->faker->unique()->safeEmail(),
            'city' => $this->faker->city(),
            'source' => 'manual',
            'stage' => LeadStageEnum::New->value,
            'expected_mrr' => $this->faker->randomFloat(2, 100, 2000),
        ];
    }

    public function stage(LeadStageEnum $stage): self
    {
        return $this->state(['stage' => $stage->value]);
    }
}
