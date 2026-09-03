<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\ZatcaRegistration;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ZatcaRegistration>
 */
class ZatcaRegistrationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'environment' => 'sandbox',
            'vat_number' => '300000000000003',
        ];
    }
}
