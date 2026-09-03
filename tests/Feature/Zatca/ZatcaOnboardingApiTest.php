<?php

namespace Tests\Feature\Zatca;

use App\Contracts\ZatcaCsrGenerator;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\ZatcaRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ZatcaOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private FakeZatcaCsrGenerator $csr;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'zatca.environment' => 'sandbox',
            'zatca.base_url' => 'https://zatca.example.test',
            'zatca.portal_url' => 'https://portal.example.test',
            'zatca.storage_disk' => 'local',
            'zatca.storage_path' => 'zatca',
        ]);

        Storage::fake('local');
        Http::preventStrayRequests();

        $this->csr = new FakeZatcaCsrGenerator;
        $this->app->instance(ZatcaCsrGenerator::class, $this->csr);

        [$this->organization, $this->branch] = $this->createTenant([
            'name' => 'Gaslah Riyadh',
            'vat_number' => '300000000000003',
            'cr_number' => '1010000000',
            'address' => 'Riyadh',
        ]);
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));
    }

    public function test_status_is_safe_and_still_renders_without_a_vat_number(): void
    {
        $this->organization->update(['vat_number' => null]);

        $response = $this->getJson('/api/zatca/status')->assertOk();

        $response
            ->assertJsonPath('data.seller.vat_number', null)
            ->assertJsonPath('data.has_csr', false)
            ->assertJsonPath('data.has_compliance_csid', false)
            ->assertJsonPath('data.compliance_passed', false)
            ->assertJsonPath('data.has_production_csid', false)
            ->assertJsonPath('data.reporting_ready', false);

        $this->assertStringNotContainsString('compliance_secret', $response->getContent());
        $this->assertStringNotContainsString('binarySecurityToken', $response->getContent());
    }

    public function test_csr_is_tenant_scoped_idempotent_and_encrypted_on_disk(): void
    {
        $first = $this->postJson('/api/zatca/onboarding/csr', ['force' => false])->assertOk();

        $first
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.subject.vat_number', '300000000000003');
        $this->assertSame(1, $this->csr->calls);
        $this->assertStringNotContainsString('PRIVATE KEY', $first->getContent());

        $registration = ZatcaRegistration::query()->sole();
        $this->assertSame($this->organization->getKey(), $registration->organization_id);
        Storage::disk('local')->assertExists("zatca/{$this->organization->getKey()}/ec-private-key.pem.enc");
        Storage::disk('local')->assertExists("zatca/{$this->organization->getKey()}/taxpayer.csr");

        $storedKey = Storage::disk('local')->get((string) $registration->private_key_path);
        $this->assertStringStartsWith('enc:v1:', $storedKey);
        $this->assertStringNotContainsString('PRIVATE KEY', $storedKey);

        $this->postJson('/api/zatca/onboarding/csr', ['force' => false])->assertOk();
        $this->assertSame(1, $this->csr->calls);

        $this->postJson('/api/zatca/onboarding/csr', ['force' => true])->assertOk();
        $this->assertSame(2, $this->csr->calls);
        $this->assertSame(1, ZatcaRegistration::query()->count());
    }

    public function test_csr_requires_a_vat_registered_organization(): void
    {
        $this->organization->update(['vat_number' => null]);

        $this->postJson('/api/zatca/onboarding/csr')->assertStatus(422);
        $this->assertSame(0, $this->csr->calls);
        $this->assertDatabaseCount('zatca_registrations', 0);
    }

    public function test_missing_key_material_invalidates_old_credentials_and_regenerates_the_egs_identity(): void
    {
        $this->postJson('/api/zatca/onboarding/csr')->assertOk();
        $registration = ZatcaRegistration::query()->sole();
        $registration->update([
            'compliance_binary_token' => 'old-token',
            'compliance_secret' => 'old-secret',
            'compliance_request_id' => 'old-request',
            'cert_fingerprint' => str_repeat('a', 64),
            'production_binary_token' => 'old-production-token',
            'production_secret' => 'old-production-secret',
            'production_request_id' => 'old-production-request',
            'complied_at' => now(),
            'onboarded_at' => now(),
        ]);
        Storage::disk('local')->delete((string) $registration->private_key_path);

        $this->getJson('/api/zatca/status')
            ->assertOk()
            ->assertJsonPath('data.has_csr', false)
            ->assertJsonPath('data.has_compliance_csid', false)
            ->assertJsonPath('data.has_production_csid', false)
            ->assertJsonPath('data.reporting_ready', false)
            ->assertJsonPath('data.certificate_fingerprint', null)
            ->assertJsonPath('data.compliance_request_id', null)
            ->assertJsonPath('data.complied_at', null)
            ->assertJsonPath('data.onboarded_at', null);

        $this->postJson('/api/zatca/onboarding/csr')->assertOk();

        $registration->refresh();
        $this->assertSame(2, $this->csr->calls);
        $this->assertNull($registration->compliance_binary_token);
        $this->assertNull($registration->compliance_secret);
        $this->assertNull($registration->compliance_request_id);
        $this->assertNull($registration->production_binary_token);
        $this->assertNull($registration->production_secret);
        $this->assertNull($registration->production_request_id);
        $this->assertNull($registration->complied_at);
        $this->assertNull($registration->onboarded_at);
        Storage::disk('local')->assertExists((string) $registration->private_key_path);
    }

    public function test_compliance_requires_a_csr_and_never_calls_the_gateway_without_it(): void
    {
        $this->postJson('/api/zatca/onboarding/compliance', ['otp' => '123456'])->assertStatus(422);

        Http::assertNothingSent();
        $this->assertDatabaseCount('zatca_registrations', 0);
    }

    public function test_compliance_uses_the_otp_header_encrypts_secrets_and_returns_only_safe_state(): void
    {
        $this->postJson('/api/zatca/onboarding/csr')->assertOk();
        $certificate = 'fake-certificate-der';
        $token = base64_encode($certificate);

        Http::fake([
            'https://zatca.example.test/compliance' => Http::response([
                'binarySecurityToken' => $token,
                'secret' => 'gateway-secret',
                'requestID' => 'request-123',
            ]),
        ]);

        $response = $this->postJson('/api/zatca/onboarding/compliance', ['otp' => '654321'])->assertOk();

        $response
            ->assertJsonPath('data.gateway_status', 200)
            ->assertJsonPath('data.request_id', 'request-123')
            ->assertJsonPath('data.has_compliance_csid', true);
        $this->assertStringNotContainsString($token, $response->getContent());
        $this->assertStringNotContainsString('gateway-secret', $response->getContent());

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://zatca.example.test/compliance'
            && $request->hasHeader('OTP', '654321')
            && $request->hasHeader('Accept-Version', 'V2')
            && $request['csr'] === base64_encode($this->csr->csrFor($this->organization)));

        $raw = DB::table('zatca_registrations')->first();
        $this->assertStringStartsWith('enc:v1:', $raw->compliance_binary_token);
        $this->assertStringStartsWith('enc:v1:', $raw->compliance_secret);
        $this->assertNotSame($token, $raw->compliance_binary_token);
        $this->assertNotSame('gateway-secret', $raw->compliance_secret);

        $registration = ZatcaRegistration::query()->sole();
        $this->assertSame($token, $registration->compliance_binary_token);
        $this->assertSame('gateway-secret', $registration->compliance_secret);
        $this->assertArrayNotHasKey('compliance_secret', $registration->toArray());

        $this->getJson('/api/zatca/status')
            ->assertOk()
            ->assertJsonPath('data.has_compliance_csid', true)
            ->assertJsonPath('data.onboarded', true)
            ->assertJsonPath('data.compliance_passed', false)
            ->assertJsonPath('data.has_production_csid', false)
            ->assertJsonPath('data.reporting_ready', false)
            ->assertJsonPath('data.compliance_request_id', 'request-123')
            ->assertJsonPath('data.certificate_fingerprint', hash('sha256', $certificate));
    }

    public function test_gateway_failure_is_mapped_without_leaking_its_body_or_storing_credentials(): void
    {
        $this->postJson('/api/zatca/onboarding/csr')->assertOk();
        Http::fake([
            'https://zatca.example.test/compliance' => Http::response([
                'message' => 'OTP invalid',
                'binarySecurityToken' => 'must-not-leak',
                'secret' => 'must-not-leak-either',
            ], 401),
        ]);

        $response = $this->postJson('/api/zatca/onboarding/compliance', ['otp' => '111111'])
            ->assertStatus(422)
            ->assertJsonPath('data.gateway_status', 401);

        $this->assertStringNotContainsString('OTP invalid', $response->getContent());
        $this->assertStringNotContainsString('must-not-leak', $response->getContent());
        $registration = ZatcaRegistration::query()->sole();
        $this->assertNull($registration->compliance_binary_token);
        $this->assertNull($registration->compliance_secret);
    }

    public function test_cashiers_cannot_read_or_mutate_onboarding(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/zatca/status')->assertStatus(403);
        $this->postJson('/api/zatca/onboarding/csr')->assertStatus(403);
        $this->postJson('/api/zatca/onboarding/compliance', ['otp' => '123456'])->assertStatus(403);

        $this->assertDatabaseCount('zatca_registrations', 0);
        Http::assertNothingSent();
    }

    public function test_request_data_cannot_select_or_overwrite_another_tenant(): void
    {
        [$foreignOrganization] = $this->createTenant(['vat_number' => '399999999999999']);
        ZatcaRegistration::factory()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'compliance_binary_token' => 'foreign-token',
            'compliance_secret' => 'foreign-secret',
            'compliance_request_id' => 'foreign-request',
        ]);

        $this->getJson('/api/zatca/status')
            ->assertOk()
            ->assertJsonPath('data.has_compliance_csid', false)
            ->assertJsonPath('data.compliance_request_id', null);

        $this->postJson('/api/zatca/onboarding/csr', [
            'organization_id' => $foreignOrganization->getKey(),
        ])->assertOk()->assertJsonPath('data.subject.vat_number', '300000000000003');

        $this->assertDatabaseHas('zatca_registrations', ['organization_id' => $this->organization->getKey()]);
        $foreign = ZatcaRegistration::query()->forOrganization($foreignOrganization->getKey())->sole();
        $this->assertSame('foreign-request', $foreign->compliance_request_id);
        $this->assertSame('foreign-secret', $foreign->compliance_secret);
    }
}

class FakeZatcaCsrGenerator implements ZatcaCsrGenerator
{
    public int $calls = 0;

    public function generate(Organization $organization): array
    {
        $this->calls++;

        return [
            'private_key_pem' => "-----BEGIN PRIVATE KEY-----\nPRIVATE-{$organization->getKey()}-{$this->calls}\n-----END PRIVATE KEY-----",
            'csr_pem' => $this->csrFor($organization),
            'subject' => [
                'organization_name' => (string) $organization->name,
                'vat_number' => (string) $organization->vat_number,
            ],
        ];
    }

    public function csrFor(Organization $organization): string
    {
        return "-----BEGIN CERTIFICATE REQUEST-----\nCSR-{$organization->getKey()}\n-----END CERTIFICATE REQUEST-----";
    }
}
