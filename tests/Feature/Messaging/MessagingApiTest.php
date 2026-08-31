<?php

namespace Tests\Feature\Messaging;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaEventEnum;
use App\Enum\Messaging\WaMessageStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\MessagingSetting;
use App\Models\Organization;
use App\Models\WaMessage;
use App\Services\Messaging\WaService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessagingApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private WaService $wa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        $this->wa = app(WaService::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Gate & quota
    |--------------------------------------------------------------------------
    */
    public function test_a_message_is_queued_and_sent_through_the_stub_provider(): void
    {
        $message = $this->queue('0501234567');

        // The sync queue runs the send job; the log provider finalises it to SENT.
        $this->assertSame(WaMessageStatusEnum::Sent, $message->fresh()->status);
    }

    public function test_an_invalid_phone_is_blocked(): void
    {
        $message = $this->queue('abc');

        $this->assertSame(WaMessageStatusEnum::Blocked, $message->status);
    }

    public function test_the_monthly_quota_blocks_further_messages(): void
    {
        MessagingSetting::query()->create(['organization_id' => $this->organization->getKey(), 'limits' => ['monthly_limit' => 1]]);

        $this->queue('0501111111');
        $second = $this->queue('0502222222');

        $this->assertSame(WaMessageStatusEnum::Blocked, $second->status);
    }

    public function test_the_org_switch_blocks_messages(): void
    {
        MessagingSetting::query()->create(['organization_id' => $this->organization->getKey(), 'config' => ['enabled' => false]]);

        $this->assertSame(WaMessageStatusEnum::Blocked, $this->queue('0503333333')->status);
    }

    /*
    |--------------------------------------------------------------------------
    | WhatsApp screen
    |--------------------------------------------------------------------------
    */
    public function test_the_message_log_hides_otp_bodies(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->wa->queue([
            'organization_id' => $this->organization->getKey(), 'to_phone' => '0505555555',
            'category' => WaCategoryEnum::Authentication, 'event_key' => WaEventEnum::Otp->value, 'body' => 'code 1234',
        ]);

        $response = $this->getJson('/api/wa/messages')->assertOk();
        $this->assertStringNotContainsString('1234', json_encode($response->json('data')));
    }

    public function test_templates_crud_and_test_send(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $id = $this->postJson('/api/wa/templates', [
            'name' => 'Ready', 'category' => 'utility', 'event_key' => 'order_ready', 'body' => 'جاهز {orderNo}',
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/wa/templates/{$id}", ['body' => 'طلبك {orderNo} جاهز'])->assertOk();
        $this->postJson('/api/wa/test', ['phone' => '0506666666'])->assertOk();
        $this->deleteJson("/api/wa/templates/{$id}")->assertOk();
    }

    public function test_the_overview_reports_this_month_grouped_three_ways(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->postJson('/api/wa/test', ['phone' => '0506666666'])->assertOk();

        $this->getJson('/api/wa/overview')
            ->assertOk()
            ->assertJsonPath('data.usage.org_used', 1)
            ->assertJsonPath('data.stats.by_event.0.key', 'test')
            ->assertJsonPath('data.stats.by_event.0.count', 1)
            ->assertJsonCount(1, 'data.stats.by_status')
            ->assertJsonCount(1, 'data.stats.by_category');
    }

    public function test_the_screen_requires_the_messaging_feature(): void
    {
        $this->organization->update(['feature_overrides' => ['messaging' => false]]);
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/wa/overview')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    */
    public function test_the_webhook_fails_closed_without_a_secret(): void
    {
        config()->set('services.whatsapp.app_secret', null);

        $this->postJson('/api/wa/webhook', [])->assertStatus(403);
    }

    public function test_the_webhook_updates_status_with_a_valid_signature(): void
    {
        config()->set('services.whatsapp.app_secret', 'sec');
        $message = WaMessage::query()->create([
            'organization_id' => $this->organization->getKey(), 'to_phone' => '0507777777', 'category' => 'utility',
            'event_key' => 'order_ready', 'body' => 'hi', 'status' => 'sent', 'provider_message_id' => 'wamid-1',
        ]);

        $payload = ['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid-1', 'status' => 'delivered']]]]]]]];
        $raw = json_encode($payload);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'sec');

        $this->call('POST', '/api/wa/webhook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $raw)->assertOk();

        $this->assertSame(WaMessageStatusEnum::Delivered, $message->fresh()->status);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function queue(string $phone): WaMessage
    {
        return $this->wa->queue([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'to_phone' => $phone,
            'category' => WaCategoryEnum::Utility,
            'event_key' => WaEventEnum::OrderCreated->value,
            'body' => 'test body',
        ]);
    }
}
