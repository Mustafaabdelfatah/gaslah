<?php

namespace Database\Factories;

use App\Enum\Catalog\ServiceTypeEnum;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        $organization = Organization::factory();
        $category = ServiceCategory::factory();

        return [
            'organization_id' => $organization,
            'category_id' => $category,
            'product_id' => Product::factory(),
            'service_type' => ServiceTypeEnum::WashIron->value,
            'name' => $this->faker->word(),
            'base_price' => 10,
            'express_surcharge' => 5,
            'is_express_available' => true,
            'is_active' => true,
        ];
    }
}
