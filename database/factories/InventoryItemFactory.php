<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\Organization;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

class InventoryItemFactory extends Factory
{
    protected $model = InventoryItem::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'branch_id' => Branch::factory(),
            'unit_id' => Unit::factory(),
            'name' => $this->faker->words(2, true),
            'cost' => 10,
            'quantity' => 100,
            'reorder_level' => 10,
            'is_active' => true,
        ];
    }

    public function low(): self
    {
        return $this->state(['quantity' => 5, 'reorder_level' => 10]);
    }
}
