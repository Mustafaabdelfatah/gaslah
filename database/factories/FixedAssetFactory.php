<?php

namespace Database\Factories;

use App\Enum\Accounting\AssetCategoryEnum;
use App\Enum\Accounting\AssetStatusEnum;
use App\Models\FixedAsset;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class FixedAssetFactory extends Factory
{
    protected $model = FixedAsset::class;

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => $this->faker->words(2, true),
            'category' => AssetCategoryEnum::Equipment->value,
            'cost' => 12000,
            'purchase_date' => '2026-01-01',
            'useful_life_months' => 12,
            'salvage_value' => 0,
            'status' => AssetStatusEnum::Active->value,
        ];
    }
}
