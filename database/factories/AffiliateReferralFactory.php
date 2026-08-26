<?php

namespace Database\Factories;

use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

class AffiliateReferralFactory extends Factory
{
    protected $model = AffiliateReferral::class;

    public function definition(): array
    {
        return [
            'affiliate_id' => Affiliate::factory(),
            'organization_id' => Organization::factory(),
            'plan_name' => 'Pro',
            'sub_amount' => 200,
            'commission' => 20,
            'status' => 'pending',
        ];
    }
}
