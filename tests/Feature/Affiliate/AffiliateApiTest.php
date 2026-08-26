<?php

namespace Tests\Feature\Affiliate;

use App\Models\Affiliate;
use App\Models\AffiliateReferral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AffiliateApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_issues_a_token_and_a_unique_code(): void
    {
        $response = $this->postJson('/api/affiliate/auth/register', [
            'name' => 'Ahmed Marketer', 'email' => 'a@ex.com', 'phone' => '0591112222',
        ])->assertCreated();

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotEmpty($response->json('data.affiliate.code'));
    }

    public function test_phone_otp_login_flow(): void
    {
        Affiliate::factory()->create(['phone' => '0593334444']);

        $code = $this->postJson('/api/affiliate/auth/request-otp', ['phone' => '0593334444'])->assertOk()->json('data.dev_code');
        $this->assertNotNull($code);

        $token = $this->postJson('/api/affiliate/auth/verify-otp', ['phone' => '0593334444', 'code' => $code])->assertOk()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/affiliate/me')->assertOk();
    }

    public function test_an_unknown_phone_gets_a_uniform_success(): void
    {
        $this->postJson('/api/affiliate/auth/request-otp', ['phone' => '0500000000'])
            ->assertOk()->assertJsonMissingPath('data.dev_code');
    }

    public function test_the_panel_reports_commission_stats(): void
    {
        $affiliate = Affiliate::factory()->create();
        AffiliateReferral::factory()->create(['affiliate_id' => $affiliate->getKey(), 'status' => 'paid', 'commission' => 20]);
        AffiliateReferral::factory()->create(['affiliate_id' => $affiliate->getKey(), 'status' => 'pending', 'commission' => 15]);
        AffiliateReferral::factory()->create(['affiliate_id' => $affiliate->getKey(), 'status' => 'cancelled', 'commission' => 99]);

        Sanctum::actingAs($affiliate);

        $this->getJson('/api/affiliate/me')
            ->assertOk()
            ->assertJsonPath('data.stats.referrals', 3)
            ->assertJsonPath('data.stats.converted', 2)
            ->assertJsonPath('data.stats.paid', 20)
            ->assertJsonPath('data.stats.pending', 15);
    }

    public function test_a_staff_token_cannot_reach_the_affiliate_panel(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/affiliate/me')->assertStatus(401);
    }

    public function test_the_referral_landing_resolves(): void
    {
        $affiliate = Affiliate::factory()->create(['code' => 'AHMED1234', 'name' => 'Ahmed']);

        $this->getJson('/api/r/AHMED1234')->assertOk()->assertJsonPath('data.found', true)->assertJsonPath('data.affiliate_name', 'Ahmed');
        $this->getJson('/api/r/NOPE')->assertOk()->assertJsonPath('data.found', false);
    }
}
