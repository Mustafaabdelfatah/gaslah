<?php

namespace Tests\Feature\Settings;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\OrganizationIntegration;
use App\Support\SecretValue;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class IntegrationSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
    }

    public function test_a_stored_secret_is_never_returned(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->putJson('/api/settings/integrations', [
            'payment' => ['gateway' => ['provider' => 'moyasar', 'secret_key' => 'sk_live_realkey']],
        ])->assertOk();

        $response = $this->getJson('/api/settings/integrations')->assertOk();

        // The value never comes back — only the fact that one is configured.
        $response->assertJsonPath('data.payment.gateway.secret_key', '')
            ->assertJsonPath('data.secrets_set.gateway_secret', true)
            ->assertJsonPath('data.payment.gateway.provider', 'moyasar');

        $this->assertStringNotContainsString('sk_live_realkey', $response->getContent());
    }

    public function test_a_secret_is_encrypted_at_rest(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->putJson('/api/settings/integrations', [
            'messaging' => ['whatsapp' => ['token' => 'wa_token_secret']],
        ])->assertOk();

        // Read the raw column: a database dump must not hand anyone the credential.
        $stored = DB::table('organization_integrations')
            ->where('organization_id', $this->organization->getKey())
            ->value('whatsapp_token');

        $this->assertNotSame('wa_token_secret', $stored);
        $this->assertStringStartsWith('enc:v1:', $stored);

        // And it round-trips through the model.
        $config = OrganizationIntegration::query()->firstWhere('organization_id', $this->organization->getKey());
        $this->assertSame('wa_token_secret', $config->whatsapp_token);
    }

    public function test_saving_with_a_blank_secret_keeps_the_stored_one(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->putJson('/api/settings/integrations', [
            'payment' => ['gateway' => ['secret_key' => 'sk_original']],
        ])->assertOk();

        // The form round-trips secrets as empty, so an unrelated save must not wipe them.
        $this->putJson('/api/settings/integrations', [
            'payment' => ['gateway' => ['provider' => 'hyperpay', 'secret_key' => '']],
        ])->assertOk()->assertJsonPath('data.secrets_set.gateway_secret', true);

        $config = OrganizationIntegration::query()->firstWhere('organization_id', $this->organization->getKey());
        $this->assertSame('sk_original', $config->gateway_secret_key);
        $this->assertSame('hyperpay', $config->gateway_provider);
    }

    public function test_an_unknown_message_event_is_not_stored(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->putJson('/api/settings/integrations', [
            'messaging' => ['events' => ['orderReady' => false, 'notARealEvent' => true]],
        ])->assertOk();

        $events = $this->getJson('/api/settings/integrations')->assertOk()->json('data.messaging.events');

        $this->assertSame(false, $events['orderReady']);
        $this->assertArrayNotHasKey('notARealEvent', $events, 'an unknown key must not sit in the config pretending to gate something');
    }

    public function test_switching_one_event_leaves_the_others_alone(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $before = $this->getJson('/api/settings/integrations')->assertOk()->json('data.messaging.events');
        $this->assertNotEmpty($before);

        // One switch, sent on its own: everything else must survive it.
        $this->putJson('/api/settings/integrations', [
            'messaging' => ['events' => ['orderCompleted' => true]],
        ])->assertOk();

        $after = $this->getJson('/api/settings/integrations')->assertOk()->json('data.messaging.events');

        $this->assertTrue($after['orderCompleted']);
        $this->assertSame(
            array_diff_key($before, ['orderCompleted' => null]),
            array_diff_key($after, ['orderCompleted' => null]),
        );
    }

    public function test_defaults_are_present_before_anything_is_configured(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/settings/integrations')
            ->assertOk()
            ->assertJsonPath('data.payment.gateway.provider', 'stub')
            ->assertJsonPath('data.payment.methods.cash', true)
            ->assertJsonPath('data.messaging.whatsapp.enabled', false)
            ->assertJsonPath('data.secrets_set.gateway_secret', false);
    }

    public function test_only_the_owner_may_change_them(): void
    {
        // A gateway key decides where the laundry's money lands.
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));

        $this->getJson('/api/settings/integrations')->assertOk();
        $this->putJson('/api/settings/integrations', ['payment' => ['gateway' => ['provider' => 'stub']]])
            ->assertStatus(403);
    }

    public function test_settings_do_not_leak_across_tenants(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->putJson('/api/settings/integrations', [
            'payment' => ['gateway' => ['secret_key' => 'sk_mine']],
        ])->assertOk();

        [, $otherBranch] = $this->createTenant();
        $this->actingAsStaff($this->createStaff($otherBranch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/settings/integrations')
            ->assertOk()
            ->assertJsonPath('data.secrets_set.gateway_secret', false);
    }

    public function test_the_cipher_refuses_a_tampered_value_and_passes_legacy_plaintext(): void
    {
        $wrapped = SecretValue::encrypt('sk_live');
        $this->assertSame('sk_live', SecretValue::decrypt($wrapped));

        // Wrapping twice would leave a value nobody can open.
        $this->assertSame($wrapped, SecretValue::encrypt($wrapped));

        // A value written before encryption existed still reads.
        $this->assertSame('legacy-plain', SecretValue::decrypt('legacy-plain'));

        // A flipped byte must fail loudly rather than yield altered plaintext: GCM
        // authenticates the ciphertext, so a tampered value has no "best effort" reading.
        //
        // Flip a character in the middle, never the last one: base64's final character
        // carries unused trailing bits, so changing it can decode to the very same bytes
        // and leave the tag valid.
        $body = substr($wrapped, strlen('enc:v1:'));
        $at = intdiv(strlen($body), 2);
        $tampered = substr($body, 0, $at).($body[$at] === 'A' ? 'B' : 'A').substr($body, $at + 1);

        $this->expectException(RuntimeException::class);
        SecretValue::decrypt('enc:v1:'.$tampered);
    }
}
